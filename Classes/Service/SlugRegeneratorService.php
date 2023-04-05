<?php

namespace Cron\CronSluggy\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\DataHandling\Model\RecordStateFactory;
use TYPO3\CMS\Core\DataHandling\SlugHelper;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Routing\Aspect\SiteAccessorTrait;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteAwareInterface;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Allows regenerating slugs on a whole page tree, optionally creating redirects
 *
 * Class SlugRegeneratorService
 * @package Cron\CronSluggy\Service
 */
class SlugRegeneratorService implements SiteAwareInterface
{

    use SiteAccessorTrait;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var OutputInterface
     */
    protected $output;

    /**
     * @var SlugHelper
     */
    protected $slugHelper;

    /**
     * @var array
     */
    protected $slugCache;

    /**
     * @var bool
     */
    protected $dryMode;

    /**
     * @var int|null
     */
    protected $createRedirects;

    /**
     * @var Site
     */
    protected $site;

    /**
     * SlugRegeneratorService constructor.
     * @param OutputInterface $output
     * @param LoggerInterface $logger
     * @param bool $dryMode
     * @param int $createRedirects
     */
    public function __construct(OutputInterface $output, LoggerInterface $logger, bool $dryMode, int $createRedirects)
    {
        $this->logger = $logger;
        $this->output = $output;
        $this->dryMode = $dryMode;
        $this->createRedirects = $createRedirects;
    }

    /**
     * Get a slug helper to work on the pages.slug field (optionally applying parent slugs or not)
     *
     * @param bool $useParentPrefix Apply parent prefixes or not?
     * @return SlugHelper
     */
    protected function getSlugHelper($useParentPrefix = false)
    {
        $fieldConfig = $GLOBALS['TCA']['pages']['columns']['slug']['config'];
        $fieldConfig['generatorOptions']['prefixParentPageSlug'] = $useParentPrefix;
        return GeneralUtility::makeInstance(
            SlugHelper::class,
            'pages', 'slug', $fieldConfig
        );
    }

    /**
     * Save a new slug for a page
     *
     * @param array $record The raw record from the pages table
     * @param string $slug The new slug to store
     * @return void
     */
    protected function updateSlug(array $record, string $slug)
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('pages');
        $connection->update('pages', ['slug' => $slug], ['uid' => $record['uid']]);
    }

    /**
     * Creates a redirect from "slug" to "page id"
     *
     * @param string $host The hostname to apply the redirect
     * @param string $path The redirect source (path from root)
     * @param int $uid The destination page UID to redirect to
     * @param int $daysToExpire How many days to expire the redirect
     * @return void
     */
    protected function createRedirect(string $host, string $path, int $uid, int $daysToExpire)
    {
        $connRedirects = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('sys_redirect');
        $target = sprintf('t3://page?uid=%s', $uid);
        $redirectRecord = [
            'createdon' => (int)time(),
            'updatedon' => (int)time(),
            'source_host' => $host,
            'source_path' => $path,
            'target' => $target,
            'endtime' => (time() + ($daysToExpire * 24 * 60 * 60))
        ];

        // Add the redirect record
        $connRedirects->insert('sys_redirect', $redirectRecord);

        $this->output->writeln(sprintf('Creating redirect from %s to %s', $path, $target));
    }

    /**
     * Regenerates a slug for a page, apply the original parent paths.
     *
     * Will also fill the property $slugCache with data as they are being created, so that we can use the parent
     * slugs in children prefixes.
     * If called recursively, only call the first time with $useParentPrefix and then without in the recursion.
     *
     * @param array $row The page record row
     * @param int $depth In which depth are we (for output cosmetics)
     * @param bool $useParentPrefix Append the prefix from database, instead from our own created (and cached)
     * @return void
     *
     * @throws SiteNotFoundException
     */
    protected function regenerateSlugForPage(array $row, int $depth, $useParentPrefix = false)
    {
        $slugHelper = $this->getSlugHelper($useParentPrefix);

        // Generate a raw new slug from the title
        $slug = $slugHelper->generate($row, $row['pid']);

        // Prefix the path to the parents from our cache if required
        if (!$useParentPrefix && $this->slugCache[$row['pid']]) {
            $cachedParent = $this->slugCache[$row['pid']];
            $slug = $this->slugCache[$row['pid']] . $slug;
        }
        // support b13/masi exclusions
        if ((bool)$row['exclude_slug_for_subpages']) {
            $this->slugCache[$row['uid']] = $cachedParent;
        } else {
            $this->slugCache[$row['uid']] = $slug === '/' ? '' : $slug;
        }

        // Make sure it is unique
        $state = RecordStateFactory::forName('pages')
            ->fromArray($row);
        $slug = $slugHelper->buildSlugForUniqueInSite($slug, $state);

        // Is is changed??
        $changedSlug = ($row['slug'] !== $slug);

        $this->output->writeln(sprintf("%s %s", str_repeat('*', $depth + 1), $row['uid']));
        if ($changedSlug) {
            $this->output->writeln(sprintf("  OLD: %s", $row['slug']));
            $this->output->writeln(sprintf("  NEW: %s", $slug));
        } else {
            $this->output->writeln(sprintf(" KEEP: %s", $row['slug']));
        }
        if (!$this->dryMode && $changedSlug) {
            // Do the actual database action of updating the slug and creating a redirect
            $this->updateSlug($row, $slug);
            if ($this->createRedirects !== null && !empty($row['slug'])) {
                $host = $this->site->getBase()->getHost();
                $this->createRedirect($host, $row['slug'], $row['uid'], $this->createRedirects);
            }
        }
    }

    /**
     * Recursively regenerate slugs on all descendants of a given page
     *
     * @param int $id uid of the page
     * @param int $depth in which depth are we
     * @param bool $begin On first call, return the page itself, on further (recursive) calls, pages with pid = $id
     *
     * @return array
     *
     * @throws SiteNotFoundException
     */
    public function executeOnPageTree(int $id, int $depth = 0, $begin = true)
    {
        $id = (int)$id;
        if ($id < 0) {
            $id = abs($id);
        }
        if ($begin) {
            $idField = 'uid';
        } else {
            $idField = 'pid';
        }
        $theList = [];
        if ($id > 0) {
            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('pages');
            $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
            $queryBuilder->select('*')
                ->from('pages')
                ->where(
                    $queryBuilder->expr()->eq($idField, $queryBuilder->createNamedParameter($id, \PDO::PARAM_INT)),
                    $queryBuilder->expr()->eq('sys_language_uid', 0)
                )
                ->orderBy('sorting');
            $statement = $queryBuilder->execute();
            while ($row = $statement->fetch()) {
                // New slug for the page itself
                $this->regenerateSlugForPage($row, $depth, $begin);
                $theList[] = $row['uid'];
                // Recurse on all subpages
                $subList = $this->executeOnPageTree($row['uid'], ++$depth, false);
                $depth--;
                $theList = array_merge($theList, $subList);
            }
        }
        return $theList;
    }

    /**
     * Regenerate slugs for a whole page tree
     *
     * @param int $rootPage The starting point in the page tree
     *
     * @return void
     *
     * @throws SiteNotFoundException
     */
    public function execute(int $rootPage)
    {
        $this->site = GeneralUtility::makeInstance(SiteFinder::class)->getSiteByPageId($rootPage);
        $this->output->writeln(sprintf('Site: %s (%s)', $this->site->getIdentifier(), (string)$this->site->getBase()));
        // Start recursion
        $this->executeOnPageTree($rootPage);
    }
}
