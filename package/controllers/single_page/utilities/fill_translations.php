<?php
namespace Concrete\Package\CommunityTranslation\Controller\SinglePage\Utilities;

use Concrete\Core\Page\Controller\PageController;
use Concrete\Package\CommunityTranslation\Src\UserException;
use Illuminate\Filesystem\Filesystem;

class FillTranslations extends PageController
{
    public function view()
    {
        $this->set('token', $this->app->make('helper/validation/token'));
        $locales = $this->app->make('community_translation/locale')->getApprovedLocales();
        $translatedLocales = array();
        $untranslatedLocales = array();
        $coreVersion = $this->app->make('community_translation/package')->getLatestVersion('');
        if ($coreVersion !== null) {
            $threshold = (int) \Package::getByHandle('community_translation')->getFileConfig()->get('options.translatedThreshold', 90);
            $statsList = $this->app->make('community_translation/stats')->get(array('', $coreVersion), $locales);
            foreach ($statsList as $stats) {
                if ($stats->getPercentage() >= $threshold) {
                    $translatedLocales[] = $stats->getLocale();
                } else {
                    $untranslatedLocales[] = $stats->getLocale();
                }
            }
        }
        if (empty($translatedLocales) || empty($untranslatedLocales)) {
            $translatedLocales = $locales;
            $untranslatedLocales = array();
        }
        $this->set('translatedLocales', $translatedLocales);
        $this->set('untranslatedLocales', $untranslatedLocales);
    }

    public function fill_in()
    {
        try {
            $valt = $this->app->make('helper/validation/token');
            if (!$valt->validate('comtra_fill_in')) {
                throw new UserException($valt->getErrorMessage());
            }
            $file = $this->request->files->get('file');
            if ($file === null) {
                throw new UserException(t('Please specify the file to be analyzed'));
            }
            if (!$file->isValid()) {
                throw new UserException($file->getErrorMessage());
            }
            $writePOT = (bool) $this->post('include-pot');
            $writePO = (bool) $this->post('include-po');
            $writeMO = (bool) $this->post('include-mo');
            if (!($writePOT || $writePO || $writeMO)) {
                throw new UserException(t('You need to specify at least one kind of file to generate'));
            }
            $locales = array();
            if ($writePO || $writeMO) {
                $localeIDs = array();
                $list = $this->post('translatedLocales');
                if (is_array($list)) {
                    $localeIDs = array_merge($localeIDs, $list);
                }
                $list = $this->post('untranslatedLocales');
                if (is_array($list)) {
                    $localeIDs = array_merge($localeIDs, $list);
                }
                $repo = $this->app->make('community_translation/locale');
                foreach ($localeIDs as $localeID) {
                    $locale = $repo->findApproved($localeID);
                    if ($locale !== null && !isset($locales[$locale->getID()])) {
                        $locales[$locale->getID()] = $locale;
                    }
                }
                $locales = array_values($locales);
                if (empty($locales)) {
                    throw new UserException(t('Please specify the languages of the .po/.mo files to generate'));
                }
            }
            $parsed = $this->app->make('community_translation/parser')->parseFile($file->getPathname());
            if ($parsed === null) {
                throw new UserException(t('No translatable string found in the uploaded file'));
            }
            $tmp = $this->app->make('community_translation/tempdir');
            $zipName = $tmp->getPath().'/out.zip';
            $zip = new \ZipArchive();
            try {
                if ($zip->open($zipName, \ZipArchive::CREATE) !== true) {
                    throw new UserException(t('Failed to create destination ZIP file'));
                }
                $zip->addEmptyDir('languages');
                if ($writePOT) {
                    $zip->addFromString('languages/messages.pot', $parsed->getPot(true)->toPoString());
                }
                if ($writePO || $writeMO) {
                    \Gettext\Generators\Mo::$includeEmptyTranslations = true;
                    $exporter = $this->app->make('community_translation/translation/exporter');
                    foreach ($locales as $locale) {
                        $dir = 'languages/'.$locale->getID();
                        $zip->addEmptyDir($dir);
                        $dir .= '/LC_MESSAGES';
                        $zip->addEmptyDir($dir);
                        $po = $parsed->getPo($locale, true);
                        $po = $exporter->fromPot($po, $locale);
                        if ($writePO) {
                            $zip->addFromString($dir.'/messages.po', $po->toPoString());
                        }
                        if ($writeMO) {
                            $zip->addFromString($dir.'/messages.mo', $po->toMoString());
                        }
                    }
                }
                $zip->close();
            } catch (\Exception $x) {
                try {
                    $zip->close();
                } catch (\Exeption $foo) {
                }
                unset($zip);
            }
            unset($zip);
            $contents = id(new Filesystem())->get($zipName);
            unset($tmp);
            \Symfony\Component\HttpFoundation\Response::create(
                $contents,
                200,
                array(
                    'Content-Type' => 'application/octet-stream',
                    'Content-Disposition' => 'attachment; filename=translations.zip',
                    'Content-Transfer-Encoding' => 'binary',
                    'Content-Length' => strlen($contents),
                    'Expires' => '0',
                )
            )->send();
            exit;
        } catch (UserException $x) {
            $message = $x->getMessage();
        } catch (\Exception $x) {
            $message = t('An unspecified error occurred');
        }
        $jsonMessage = json_encode($message);
        \Symfony\Component\HttpFoundation\Response::create(
            <<<EOT
<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="content-type" content="text/html; charset=UTF-8" />
    <script>
        window.parent.alert($jsonMessage);
   </script>
</head>
</html>
EOT
            ,
            200,
            array(
                'Content-Type' => 'text/html; charset=' . APP_CHARSET,
                'Cache-Control' => 'no-cache',
                'X-Frame-Options' => 'SAMEORIGIN',
            )
        )->send();
        exit();
    }
}
