<?php

namespace Cron\CronSluggy\Service;

use Cron\CronSluggy\ColorDiffer;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\DataHandling\Model\RecordStateFactory;
use TYPO3\CMS\Core\DataHandling\SlugHelper;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Routing\Aspect\SiteAccessorTrait;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteAwareInterface;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
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
     * @var bool
     */
    protected string $outputFormat;

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
     * @param string $outputFormat
     */
    public function __construct(OutputInterface $output, LoggerInterface $logger, bool $dryMode, int $createRedirects, string $outputFormat)
    {
        $this->logger = $logger;
        $this->output = $output;
        $this->dryMode = $dryMode;
        $this->createRedirects = $createRedirects;
        $this->outputFormat = $outputFormat;
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
            'pages',
            'slug',
            $fieldConfig
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

        if ($this->outputFormat === 'plain') {
            $this->output->writeln(sprintf('Creating redirect from %s to %s', $path, $target));
        }
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

        if (! $useParentPrefix) {
            // a nested site root? Make sure we create a new slug, because default TYPO3 shortcuts this to a single '/'
            $row['is_siteroot'] = 0;
        }

        // Generate a raw new slug from the title
        $slug = $slugHelper->generate($row, $row['pid']);

        // Prefix the path to the parents from our cache if required
        if (!$useParentPrefix && isset($this->slugCache[$row['pid']])) {
            $slug = $this->slugCache[$row['pid']] . $slug;
        }

        $this->cacheSlugForPage($slug, $row);

        // Make sure it is unique
        $state = RecordStateFactory::forName('pages')
            ->fromArray($row);
        $slug = $slugHelper->buildSlugForUniqueInSite($slug, $state);

        // Is is changed??
        $changedSlug = ($row['slug'] !== $slug);

        if (!$this->dryMode && $changedSlug) {
            // Do the actual database action of updating the slug and creating a redirect
            $this->updateSlug($row, $slug);
            if ($this->createRedirects !== null && !empty($row['slug'])) {
                $host = $this->site->getBase()->getHost();
                $port = $this->site->getBase()->getPort();
                if ($port) {
                    $host .= ':' . $port;
                }
                $path = $this->site->getBase()->getPath();
                $sourcePath =  '/' . trim($path, '/') . '/' . ltrim($row['slug'], '/');
                $this->createRedirect($host, $sourcePath, $row['uid'], $this->createRedirects);
            }
        }

        if ($changedSlug || $this->output->isVerbose()) {
            if ($this->outputFormat === 'csv') {
                $this->output->writeln(sprintf(
                    '%s;%s;%s;%s',
                    $row['uid'],
                    $row['hidden'] ? 'hidden' : '',
                    $changedSlug ? $slug : 'UNCHANGED',
                    $row['slug'],
                ));
            } elseif ($this->outputFormat === 'html') {
                $diff = new ColorDiffer();
                $this->output->writeln(sprintf(
                    "<tr class='%s'><td>%s</td><td>%s</td><td>%s</td><td class='table-%s'>%s</td><td>%s</td></tr>\n",
                    $row['hidden'] ? 'table-secondary' : '',
                    $row['uid'],
                    match ($row['doktype']) {
                        PageRepository::DOKTYPE_DEFAULT => '',
                        PageRepository::DOKTYPE_LINK => 'External link',
                        PageRepository::DOKTYPE_SHORTCUT => 'Shortcut',
                        PageRepository::DOKTYPE_BE_USER_SECTION => 'BE user section',
                        PageRepository::DOKTYPE_MOUNTPOINT => 'Mountpoint',
                        PageRepository::DOKTYPE_SPACER => 'Spacer',
                        PageRepository::DOKTYPE_SYSFOLDER => 'Folder',
                        PageRepository::DOKTYPE_RECYCLER => 'Recycler',
                    },
                    $row['hidden'] ? 'hidden' : '',
                    $changedSlug ? 'warning' : 'success',
                    $changedSlug
                        ? sprintf(
                            '<span title="%s -&gt; %s">%s</span>',
                            $row['slug'],
                            $slug,
                            $diff->getDifference($row['slug'], $slug)
                        )
                        : $slug,
                    $changedSlug && $this->createRedirects
                        ? sprintf(
                            '<a href="%s">%s</a>',
                            rtrim((string)$this->site->getBase(), '/') . '/' . ltrim($row['slug'], '/'),
                            $sourcePath
                        )
                        : ''
                ));
            } else {
                $this->output->writeln(sprintf("%s %s%s", str_repeat('*', $depth + 1), $row['uid'], $row['hidden'] ? ' (HIDDEN)' : ''));
                if ($changedSlug) {
                    $this->output->writeln(sprintf("  OLD: %s", $row['slug']));
                    $this->output->writeln(sprintf("  NEW: %s", $slug));
                } else {
                    $this->output->writeln(sprintf(" KEEP: %s", $row['slug']));
                }
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

        $fieldSeparator = $GLOBALS['TCA']['pages']['columns']['slug']['config']['generatorOptions']['fieldSeparator'] ?? '/';
        $fields = $GLOBALS['TCA']['pages']['columns']['slug']['config']['generatorOptions']['fields'] ?? [];
        $replacements = $GLOBALS['TCA']['pages']['columns']['slug']['config']['generatorOptions']['replacements'] ?? [];
        $postModifiers = $GLOBALS['TCA']['pages']['columns']['slug']['config']['generatorOptions']['postModifiers'] ?? [];

        $slugFormat = [];
        foreach ($fields as $field) {
            if (is_array($field)) {
                $slugFormat[] = '{ ' . join(' // ', $field) . ' }';
            } else {
                $slugFormat[] = '{ ' . $field . ' }';
            }
        }

        if ($this->outputFormat === 'csv') {
            $this->output->writeln('uid;hidden;old_slug;new_slug');
        } elseif ($this->outputFormat === 'html') {
            $this->output->writeln('<html><head>');
            #$this->output->writeln('<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>');
            $this->output->writeln('<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">');
            #$this->output->writeln('<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">');
            $this->output->writeln('</head><body>');
            $this->output->writeln(sprintf('<h1>Site: %s (%s)</h1>', $this->site->getIdentifier(), (string)$this->site->getBase()));
            $this->output->writeln('<h4>Configuration</h4><ul>');
            $this->output->writeln('<li>Slug Format: <code>' . join(' ' . $fieldSeparator . ' ', $slugFormat) . '</code></li>');
            foreach ($replacements as $char => $replace) {
                $this->output->writeln(sprintf('<li>Replace <code>%s</code> with <code>%s</code>', $char, $replace) . '</li>');
            }
            foreach ($postModifiers as $postModifier) {
                $this->output->writeln('<li>Post Modifier: <code>' . $postModifier . '</code></li>');
            }
            $this->output->writeln('</ul><table class="table"><tr><th>UID</th><th>Type</th><th>Hidden?</th><th>Slug</th><th>Redirect</th></tr>');
        } else {
            $this->output->writeln(sprintf('Site: %s (%s)', $this->site->getIdentifier(), (string)$this->site->getBase()));
            $this->output->writeln('Configuration:');
            $this->output->writeln('- Slug Format: ' . join(' ' . $fieldSeparator . ' ', $slugFormat));
            foreach ($replacements as $char => $replace) {
                $this->output->writeln(sprintf('- Replace "%s" with "%s"', $char, $replace));
            }
            foreach ($postModifiers as $postModifier) {
                $this->output->writeln('- Post Modifier: ' . $postModifier);
            }
            $this->output->writeln('');
        }

        if ($rootPage !== $this->site->getRootPageId()) {
            // Prefill slug cache
            $additionalFields = [];
            if (ExtensionManagementUtility::isLoaded('masi')) {
                $additionalFields[] = 'exclude_slug_for_subpages';
            }
            $rootline = BackendUtility::BEgetRootLine($rootPage, '', false, $additionalFields);
            $rootline = array_reverse($rootline);
            foreach ($rootline as $page) {
                if ($page['uid']) {
                    $this->cacheSlugForPage($page['slug'], $page);
                }
            }
        }

        // Start recursion
        $this->executeOnPageTree($rootPage);

        if ($this->outputFormat === 'html') {
            $this->output->writeln("</table></body></html>\n");
        }
    }

    private function cacheSlugForPage(string $slug, array $row): void
    {
        // support b13/masi exclusions
        if (isset($row['exclude_slug_for_subpages'])) {
            if ((bool)$row['exclude_slug_for_subpages']) {
                $this->slugCache[$row['uid']] = $this->slugCache[$row['pid']];
            } else {
                $this->slugCache[$row['uid']] = $slug === '/' ? '' : $slug;
            }
        } else {
            // support doktype exclusion of TYPO3
            if (in_array($row['doktype'], [
                PageRepository::DOKTYPE_SPACER,
                PageRepository::DOKTYPE_RECYCLER,
                PageRepository::DOKTYPE_SYSFOLDER,
            ])) {
                // skip this slug and use the parent pages slugs
                $this->slugCache[$row['uid']] = $this->slugCache[$row['pid']];
            } else {
                $this->slugCache[$row['uid']] = $slug === '/' ? '' : $slug;
            }
        }
    }
}
