<?php
namespace Concrete\Package\CommunityTranslation\Src\Translation;

use Concrete\Core\Application\Application;
use Concrete\Package\CommunityTranslation\Src\UserException;
use Concrete\Package\CommunityTranslation\Src\Service\Access;

class Importer implements \Concrete\Core\Application\ApplicationAwareInterface
{
    const IMPORT_BATCH_SIZE = 50;

    /**
     * The application object.
     *
     * @var Application
     */
    protected $app;

    /**
     * Set the application object.
     *
     * @param Application $application
     */
    public function setApplication(Application $app)
    {
        $this->app = $app;
    }

    /**
     * Import the translated strings for a specific locale.
     *
     * @param \Gettext\Translations $translations
     * @param \Concrete\Package\CommunityTranslation\Src\Locale\Locale|string $locale
     * @param array $options {
     *
     *     @var bool|null $maySetAsReviewed
     *     @var bool $checkLocale
     *     @var bool $checkPlural
     * }
     *
     * @throws UserException
     *
     * @return ImportResult
     */
    public function import(\Gettext\Translations $translations, $locale, $options = array())
    {
        // Check locale
        if (!$locale instanceof \Concrete\Package\CommunityTranslation\Src\Locale\Locale) {
            $l = $this->app->make('community_translation/locale')->find($locale);
            if ($l === null) {
                throw new UserException(t('Invalid locale identifier: %s', $locale));
            }
            $locale = $l;
        }
        if ($locale->isSource()) {
            throw new UserException(t("The locale '%s' is the source one.", $locale->getDisplayName()));
        }
        if (!$locale->isApproved()) {
            throw new UserException(t("The locale '%s' is not approved.", $locale->getDisplayName()));
        }
        // Check $reviewed
        if (isset($options['maySetAsReviewed'])) {
            $maySetAsReviewed = $options['maySetAsReviewed'] ? true : false;
        } else {
            $access = $this->app->make('community_translation/access')->getLocaleAccess($locale);
            if ($access < Access::TRANSLATE) {
                throw new UserException(t("No access for the locale '%s'.", $locale->getDisplayName()));
            }
            $maySetAsReviewed = ($access >= Access::ADMIN) ? true : false;
        }
        if (isset($options['checkLocale']) && $options['checkLocale']) {
            if (@strcasecmp(strtolower(str_replace('-', '_', $translations->getLanguage())), strtolower($locale->getID())) !== 0) {
                $name = \Punic\Language::getName($translations->getLanguage());
                if ($name) {
                    throw new UserException(t('The specified file contains translations for %1$s and not for %2$s', $name, $locale->getDisplayName()));
                } else {
                    throw new UserException(t('It was not possible to determine the language of the uploaded file.'));
                }
            }
        }
        if (isset($options['checkPlural']) && $options['checkPlural']) {
            $pluralForms = $translations->getPluralForms();
            $pluralCount = isset($pluralForms) ? $pluralForms[0] : null;
            if ($pluralCount === null || $pluralCount !== $locale->getPluralCount()) {
                foreach ($translations as $translation) {
                    /* @var \Gettext\Translation $translation */
                    if ($translation->hasPlural() && $translation->hasTranslation()) {
                        if ($pluralCount === null) {
                            throw new UserException(t('For the language %1$s there should be %2$d plural forms, but in your file this is not specified', $locale->getDisplayName(), $locale->getPluralCount()));
                        } else {
                            throw new UserException(t('For the language %1$s there should be %2$d plural forms, but in your file there are %3$d', $locale->getDisplayName(), $locale->getPluralCount(), $pluralCount));
                        }
                    }
                }
            }
        }
        // Some vars
        $me = new \User();
        $userID = (int) ($me->isRegistered() ? $me->getUserID() : USER_SUPER_ID);
        $pluralCount = $locale->getPluralCount();
        // Start working - This a bit hacky but it's lightning fast
        $connection = $this->app->make('community_translation/em')->getConnection();
        $nowExpression = $connection->getDatabasePlatform()->getNowExpression();
        $now = id(new \DateTime())->format($connection->getDatabasePlatform()->getDateTimeFormatString());
        $connection->beginTransaction();
        $translatablesChanged = array();
        $result = new ImportResult();
        try {
            // Prepare some queries
            $searchQuery = $connection->prepare('
                select
                    Translatables.tID as translatableID,
                    Translations.*
                from
                    Translatables
                    left join Translations on Translatables.tID = Translations.tTranslatable and '.$connection->quote($locale->getID()).' = Translations.tLocale
                where
                    Translatables.tHash = ?
            ')->getWrappedStatement();
            /* @var \Doctrine\DBAL\Driver\Statement $searchQuery */
            $insertQueryFields = 'tCreatedOn, tCreatedBy, tLocale, tCurrent, tCurrentSince, tReviewed, tNeedReview, tTranslatable, tText0, tText1, tText2, tText3, tText4, tText5';
            $insertQueryChunk = ' ('.implode(', ', array(
                $nowExpression,
                $userID,
                $connection->quote($locale->getID()),
                '?', // tCurrent
                '?', // tCurrentSince
                '?', // tReviewed
                '?', // tNeedReview
                '?', // tTranslatable
                '?, ?, ?, ?, ?, ?', // tText0... tText5
            )).'),';
            $insertQuery = $connection->prepare(
                'INSERT INTO Translations ('.$insertQueryFields.') VALUES '
                .rtrim(str_repeat($insertQueryChunk, self::IMPORT_BATCH_SIZE), ',')
            )->getWrappedStatement();
            /* @var \Doctrine\DBAL\Driver\Statement $insertQuery */
            $insertParams = array();
            $insertCount = 0;
            $unsetCurrentTranslationQuery = $connection->prepare(
                'UPDATE Translations SET tCurrent = NULL, tCurrentSince = NULL, tReviewed = 0, tNeedReview = 0 WHERE tID = ? LIMIT 1'
            )->getWrappedStatement();
            /* @var \Doctrine\DBAL\Driver\Statement $unsetCurrentTranslationQuery */
            $setCurrentTranslationQuery = $connection->prepare(
                'UPDATE Translations SET tCurrent = 1, tCurrentSince = '.$nowExpression.', tReviewed = ?, tNeedReview = 0 WHERE tID = ? LIMIT 1'
            )->getWrappedStatement();
            /* @var \Doctrine\DBAL\Driver\Statement $setCurrentTranslationQuery */

            // Check every strings to be imported
            foreach ($translations as $translationKey => $translation) {
                /* @var \Gettext\Translation $translation */
                if (!$translation->hasTranslation()) {
                    // This $translation instance is not translated
                    ++$result->emptyTranslations;
                    continue;
                }
                $isPlural = $translation->hasPlural();
                if ($isPlural && $pluralCount > 1 && !$translation->hasPluralTranslation()) {
                    // This plural form of the $translation instance is not translated
                    ++$result->emptyTranslations;
                    continue;
                }
                // Let's look for this translation
                $translatableID = null;
                $currentRow = null;
                $sameRow = null;
                // Read the current translations and look for the current one and determine if we already have this new translation
                $searchQuery->execute(array(md5($isPlural ? ("$translationKey\005".$translation->getPlural()) : $translationKey)));
                while (($row = $searchQuery->fetch()) !== false) {
                    if ($translatableID === null) {
                        $translatableID = (int) $row['translatableID'];
                    }
                    if (!isset($row['tID'])) {
                        break;
                    }
                    if ($currentRow === null && $row['tCurrent'] === '1') {
                        $currentRow = $row;
                    }
                    if ($sameRow === null && $this->rowSameAsTranslation($row, $translation, $isPlural, $pluralCount)) {
                        $sameRow = $row;
                    }
                }
                $searchQuery->closeCursor();
                if ($translatableID === null) {
                    // No translatable string for this translation
                    ++$result->unknownStrings;
                    continue;
                }
                if ($maySetAsReviewed === true) {
                    $reviewed = in_array('fuzzy', $translation->getFlags(), true) ? 0 : 1;
                } else {
                    $reviewed = 0;
                }
                if ($sameRow === null) {
                    // This translation is not already present - Let's add it
                    if ($currentRow === null) {
                        // No current translation for this string: add this new one and mark it as the current one
                        $addCurrent = 1;
                        $addReviewed = $reviewed;
                        $addNeedReview = 0;
                        $translatablesChanged[] = $translatableID;
                        ++$result->addedActivated;
                    } elseif ($reviewed === 1 || $currentRow['tReviewed'] === '0') {
                        // There's already a current translation for this string, but we'll activate this new one
                        $unsetCurrentTranslationQuery->execute(array($currentRow['tID']));
                        $addCurrent = 1;
                        $addReviewed = $reviewed;
                        $addNeedReview = 0;
                        $translatablesChanged[] = $translatableID;
                        ++$result->addedActivated;
                    } else {
                        // Let keep the previously current translation as the current one, but let's add this new one
                        $addCurrent = null;
                        $addReviewed = 0;
                        $addNeedReview = 1;
                        ++$result->addedNeedReview;
                    }
                    // Add the new record to the queue
                    $insertParams[] = $addCurrent;
                    $insertParams[] = ($addCurrent === 1) ? $now : null;
                    $insertParams[] = $addReviewed;
                    $insertParams[] = $addNeedReview;
                    $insertParams[] = $translatableID;
                    $insertParams[] = $translation->getTranslation();
                    for ($p = 1; $p <= 5; ++$p) {
                        $insertParams[] = ($isPlural && $p < $pluralCount) ? $translation->getPluralTranslation($p - 1) : '';
                    }
                    ++$insertCount;
                    if ($insertCount === self::IMPORT_BATCH_SIZE) {
                        $insertQuery->execute($insertParams);
                        $insertParams = array();
                        $insertCount = 0;
                    }
                } elseif ($currentRow === null) {
                    // This translation is already present, but there's no current translation: let's activate it
                    $setCurrentTranslationQuery->execute(array(($reviewed === 1 || $sameRow['tReviewed'] === '1') ? 1 : 0, $sameRow['tID']));
                    $translatablesChanged[] = $translatableID;
                    ++$result->addedActivated;
                } elseif ($sameRow['tCurrent'] === '1') {
                    // This translation is already present and it's the current one
                    if ($reviewed === 1 && $sameRow['tReviewed'] === '0') {
                        // Let's mark the translation as reviewed
                        $setCurrentTranslationQuery->execute(array(1, $sameRow['tID']));
                        ++$result->existingActiveReviewed;
                    } else {
                        ++$result->existingActiveUntouched;
                    }
                } else {
                    // This translation exists, but we have already another translation that's the current one
                    if ($reviewed === 1 || $currentRow['tReviewed'] === '0') {
                        // Let's make the new translation the current one
                        $unsetCurrentTranslationQuery->execute(array($currentRow['tID']));
                        $setCurrentTranslationQuery->execute(array($reviewed, $sameRow['tID']));
                        $translatablesChanged[] = $translatableID;
                        ++$result->existingActivated;
                    } else {
                        ++$result->existingInactiveUntouched;
                    }
                }
            }
            if ($insertCount > 0) {
                $connection->executeQuery(
                    'INSERT INTO Translations ('.$insertQueryFields.') VALUES '.rtrim(str_repeat($insertQueryChunk, $insertCount), ','),
                    $insertParams
                );
            }
            $connection->commit();
        } catch (\Exception $x) {
            try {
                $connection->rollBack();
            } catch (\Exception $foo) {
            }
            throw $x;
        }
        if (!empty($translatablesChanged)) {
            $this->app->make('community_translation/stats')->resetForLocaleTranslatables($locale, $translatablesChanged);
        }

        return $result;
    }

    /**
     * Is a database row the same as the translation?
     *
     * @param array $row
     * @param \Gettext\Translation $translation
     * @param bool $isPlural
     * @param int $pluralCount
     *
     * @return bool
     */
    protected function rowSameAsTranslation(array $row, \Gettext\Translation $translation, $isPlural, $pluralCount)
    {
        if ($row['tText0'] !== $translation->getTranslation()) {
            return false;
        }
        if (!$isPlural) {
            return true;
        }
        $same = true;
        switch ($pluralCount) {
            case 6:
                if ($same && $row['tText5'] !== $translation->getPluralTranslation(4)) {
                    $same = false;
                }
                /* @noinspection PhpMissingBreakStatementInspection */
            case 5:
                if ($same && $row['tText4'] !== $translation->getPluralTranslation(3)) {
                    $same = false;
                }
                /* @noinspection PhpMissingBreakStatementInspection */
            case 4:
                if ($same && $row['tText3'] !== $translation->getPluralTranslation(2)) {
                    $same = false;
                }
                /* @noinspection PhpMissingBreakStatementInspection */
            case 3:
                if ($same && $row['tText2'] !== $translation->getPluralTranslation(1)) {
                    $same = false;
                }
                /* @noinspection PhpMissingBreakStatementInspection */
            case 2:
                if ($same && $row['tText1'] !== $translation->getPluralTranslation(0)) {
                    $same = false;
                }
                break;
        }

        return $same;
    }
}
