<?php
namespace Concrete\Package\CommunityTranslation\Src\Api;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Concrete\Package\CommunityTranslation\Src\Locale\Locale;
use Concrete\Package\CommunityTranslation\Src\UserException;
use Concrete\Package\CommunityTranslation\Src\Service\Parser\Parser;

class EntryPoint extends \Concrete\Core\Controller\AbstractController
{
    /**
     * @var UserControl|null
     */
    protected $userControl = null;

    /**
     * @param UserControl $userControl
     */
    public function setUserControl(UserControl $userControl)
    {
        $this->userControl = $userControl;
    }

    /**
     * @return UserControl
     */
    public function getUserControl()
    {
        if ($this->userControl === null) {
            $this->userControl = $this->app->make('Concrete\Package\CommunityTranslation\Src\Api\UserControl');
            $this->userControl->setRequest($this->request);
        }

        return $this->userControl;
    }

    /**
     * @param Locale[]|Locale $locale
     */
    protected function localesToArray($locales, $cb = null)
    {
        if (is_array($locales)) {
            $single = false;
        } else {
            $single = true;
            $locales = array($locales);
        }
        $list = array();
        foreach ($locales as $locale) {
            $item = array(
                'id' => $locale->getID(),
                'name' => $locale->getName(),
            );
            if ($cb !== null) {
                $item = call_user_func($cb, $locale, $item);
            }
            if ($item !== null) {
                $list[] = $item;
            }
        }

        return $single ? $list[0] : $list;
    }

    /**
     * Check the access to a specific group of API functions.
     *
     * @param string $configKey 'stats', 'download', 'import_packages', ...
     */
    protected function checkAccess($configKey)
    {
        $config = \Package::getByHandle('community_translation')->getFileConfig();
        $this->getUserControl()->checkRequest($config->get('options.api.access.'.$configKey));
    }

    /**
     * @example http://www.example.com/api/locales/
     */
    public function getApprovedLocales()
    {
        try {
            $this->checkAccess('stats');
        } catch (AccessDeniedException $x) {
            return JsonResponse::create(array('error' => $x->getMessage()), 401);
        }
        $locales = $this->app->make('community_translation/locale')->getApprovedLocales();

        return JsonResponse::create($this->localesToArray($locales));
    }

    /**
     * @example http://www.example.com/api/locales//dev-5.7/90/
     */
    public function getLocalesForPackage($packageHandle, $packageVersion, $minimumLevel)
    {
        try {
            $this->checkAccess('stats');
        } catch (AccessDeniedException $x) {
            return JsonResponse::create(array('error' => $x->getMessage()), 401);
        }
        $package = $this->app->make('community_translation/package')->findOneBy(array(
            'pHandle' => (string) $packageHandle,
            'pVersion' => (string) $packageVersion,
        ));
        if ($package === null) {
            return JsonResponse::create(
                array('error' => 'Unable to find the specified package'),
                404
            );
        }
        $minimumLevel = (int) $minimumLevel;
        $result = array();
        $allLocales = $this->app->make('community_translation/locale')->getApprovedLocales();
        $stats = $this->app->make('community_translation/stats')->get($package, $allLocales);
        $utc = new \DateTimeZone('UTC');
        $result = $this->localesToArray(
            $allLocales,
            function (Locale $locale, array $item) use ($stats, $minimumLevel, $utc) {
                $result = null;
                foreach ($stats as $stat) {
                    if ($stat->getLocale() === $locale) {
                        if ($stat->getPercentage() >= $minimumLevel) {
                            $item['total'] = $stat->getTotal();
                            $item['translated'] = $stat->getTranslated();
                            $item['progressShown'] = $stat->getPercentage(true);
                            $dt = $stat->getLastUpdated();
                            if ($dt === null) {
                                $item['updated'] = null;
                            } else {
                                $dt = clone $dt;
                                $dt->setTimezone($utc);
                                $item['updated'] = $dt->format('c');
                            }
                            $result = $item;
                        }
                        break;
                    }
                }

                return $result;
            }
        );

        return JsonResponse::create($result);
    }

    /**
     * @example http://www.example.com/api/packages/
     */
    public function getAvailablePackageHandles()
    {
        try {
            $this->checkAccess('stats');
        } catch (AccessDeniedException $x) {
            return JsonResponse::create(array('error' => $x->getMessage()), 401);
        }
        $em = $this->app->make('community_translation/em');
        $handles = $em->getConnection()->executeQuery('select distinct pHandle from TranslatedPackages')->fetchAll(\PDO::FETCH_COLUMN);

        return JsonResponse::create($handles);
    }

    /**
     * @example http://www.example.com/api/package//versions/
     */
    public function getAvailablePackageVersions($packageHandle)
    {
        try {
            $this->checkAccess('stats');
        } catch (AccessDeniedException $x) {
            return JsonResponse::create(array('error' => $x->getMessage()), 401);
        }
        $em = $this->app->make('community_translation/em');
        $handles = $em->getConnection()->executeQuery('select distinct pVersion from TranslatedPackages where pHandle = ?', array((string) $packageHandle))->fetchAll(\PDO::FETCH_COLUMN);
        if (empty($handles)) {
            return JsonResponse::create(
                array('error' => 'Unable to find the specified package'),
                404
            );
        }

        return JsonResponse::create($handles);
    }

    /**
     * @example http://www.example.com/api/po//dev-5.7/it_IT/
     */
    public function getPackagePo($packageHandle, $packageVersion, $localeID)
    {
        try {
            $this->checkAccess('download');
        } catch (AccessDeniedException $x) {
            return Response::create($x->getMessage(), 401);
        }

        return $this->getPackageTranslations($packageHandle, $packageVersion, $localeID, false);
    }

    /**
     * @example http://www.example.com/api/mo//dev-5.7/it_IT/
     */
    public function getPackageMo($packageHandle, $packageVersion, $localeID)
    {
        try {
            $this->checkAccess('download');
        } catch (AccessDeniedException $x) {
            return Response::create($x->getMessage(), 401);
        }

        return $this->getPackageTranslations($packageHandle, $packageVersion, $localeID, true);
    }

    protected function getPackageTranslations($packageHandle, $packageVersion, $localeID, $compiled)
    {
        $package = $this->app->make('community_translation/package')->findOneBy(array('pHandle' => $packageHandle, 'pVersion' => $packageVersion));
        if ($package === null) {
            return Response::create(
                'Unable to find the specified package',
                404
            );
        }
        $locale = $this->app->make('community_translation/locale')->findApproved($localeID);
        if ($locale === null) {
            return Response::create(
                'Unable to find the specified locale',
                404
            );
        }
        $translations = $this->app->make('community_translation/translation/exporter')->forPackage($package, $locale);
        if ($compiled) {
            \Gettext\Generators\Mo::$includeEmptyTranslations = true;
            $data = $translations->toMoString();
            $filename = $locale->getID().'.mo';
        } else {
            $data = $translations->toPoString();
            $filename = $locale->getID().'.po';
        }

        return Response::create(
            $data,
            200,
            array(
                'Content-Disposition' => 'attachment; filename='.$filename,
                'Content-Type' => 'application/octet-stream',
                'Content-Length' => strlen($data),
            )
        );
    }

    public function importPackageTranslatable()
    {
        try {
            $this->checkAccess('import_packages');
        } catch (AccessDeniedException $x) {
            return Response::create($x->getMessage(), 401);
        }
        try {
            $packageHandle = $this->post('handle');
            $packageHandle = is_string($packageHandle) ? trim($packageHandle) : '';
            if ($packageHandle === '') {
                throw new UserException('Package handle not specified');
            }
            $packageVersion = $this->post('version');
            $packageVersion = is_string($packageVersion) ? trim($packageVersion) : '';
            if ($packageVersion === '') {
                throw new UserException('Package version not specified');
            }
            $archive = $this->request->files->get('archive');
            if ($archive === null) {
                throw new UserException('Package archive not received');
            }
            if (!$archive->isValid()) {
                throw new UserException(sprintf('Package archive not correctly received: %s', $file->getErrorMessage()));
            }
            $parsed = $this->app->make('community_translation/parser')->parseZip($file->getPathname(), 'packages/'.$packageHandle, Parser::GETTEXT_NONE);
            $pot = ($parsed === null) ? null : $parsed->getPot(false);
            if ($pot === null || count($pot) === 0) {
                throw new UserException('No translatable strings found');
            }
            $changed = $this->app->make('community_translation/translatable/importer')->importTranslations($pot, $packageHandle, $packageVersion);

            return JsonResponse::create(
                array('changed' => $changed),
                200
            );
        } catch (UserException $x) {
            return JsonResponse::create(
                array('error' => $x->getMessage()),
                400
            );
        } catch (\Exception $x) {
            return JsonResponse::create(
                array('error' => 'Unspecified error'),
                400
            );
        }
    }
}
