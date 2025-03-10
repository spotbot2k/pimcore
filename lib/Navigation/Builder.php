<?php

/**
 * Pimcore
 *
 * This source file is available under two different licenses:
 * - GNU General Public License version 3 (GPLv3)
 * - Pimcore Commercial License (PCL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 *  @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.org)
 *  @license    http://www.pimcore.org/license     GPLv3 and PCL
 */

namespace Pimcore\Navigation;

use Pimcore\Cache as CacheManager;
use Pimcore\Http\RequestHelper;
use Pimcore\Logger;
use Pimcore\Model\Document;
use Pimcore\Model\Site;
use Pimcore\Navigation\Iterator\PrefixRecursiveFilterIterator;
use Pimcore\Navigation\Page\Document as DocumentPage;
use Pimcore\Navigation\Page\Url;

class Builder
{
    /**
     * @var RequestHelper
     */
    private $requestHelper;

    /**
     * @internal
     *
     * @var string
     */
    protected $htmlMenuIdPrefix;

    /**
     * @internal
     *
     * @var string
     */
    protected $pageClass = DocumentPage::class;

    /**
     * @var int
     */
    private $currentLevel = 0;

    /**
     * @var array
     */
    private $navCacheTags = [];

    /**
     * @param RequestHelper $requestHelper
     * @param string|null $pageClass
     */
    public function __construct(RequestHelper $requestHelper, ?string $pageClass = null)
    {
        $this->requestHelper = $requestHelper;

        if (null !== $pageClass) {
            $this->pageClass = $pageClass;
        }
    }

    /**
     * @param Document|null $activeDocument
     * @param Document|null $navigationRootDocument
     * @param string|null $htmlMenuIdPrefix
     * @param \Closure|null $pageCallback
     * @param bool|string $cache
     * @param int|null $maxDepth
     * @param int|null $cacheLifetime
     *
     * @return mixed|\Pimcore\Navigation\Container
     *
     * @throws \Exception
     */
    public function getNavigation($activeDocument = null, $navigationRootDocument = null, $htmlMenuIdPrefix = null, $pageCallback = null, $cache = true, ?int $maxDepth = null, ?int $cacheLifetime = null)
    {
        $cacheEnabled = $cache !== false;

        $this->htmlMenuIdPrefix = $htmlMenuIdPrefix;

        if (!$navigationRootDocument) {
            $navigationRootDocument = Document::getById(1);
        }

        // the cache key consists out of the ID and the class name (eg. for hardlinks) of the root document and the optional html prefix
        $cacheKeys = ['root_id__' . $navigationRootDocument->getId(), $htmlMenuIdPrefix, get_class($navigationRootDocument)];

        if (Site::isSiteRequest()) {
            $site = Site::getCurrentSite();
            $cacheKeys[] = 'site__' . $site->getId();
        }

        if (is_string($cache)) {
            $cacheKeys[] = 'custom__' . $cache;
        }

        if ($pageCallback instanceof \Closure) {
            $cacheKeys[] = 'pageCallback_' . closureHash($pageCallback);
        }

        if ($maxDepth) {
            $cacheKeys[] = 'maxDepth_' . $maxDepth;
        }

        $cacheKey = 'nav_' . md5(serialize($cacheKeys));
        $navigation = CacheManager::load($cacheKey);

        if (!$navigation || !$cacheEnabled) {
            $navigation = new \Pimcore\Navigation\Container();

            $this->navCacheTags = ['output', 'navigation'];

            if ($navigationRootDocument->hasChildren()) {
                $this->currentLevel = 0;
                $rootPage = $this->buildNextLevel($navigationRootDocument, true, $pageCallback, [], $maxDepth);
                $navigation->addPages($rootPage);
            }

            // we need to force caching here, otherwise the active classes and other settings will be set and later
            // also written into cache (pass-by-reference) ... when serializing the data directly here, we don't have this problem
            if ($cacheEnabled) {
                CacheManager::save($navigation, $cacheKey, $this->navCacheTags, $cacheLifetime, 999, true);
            }
        }

        // set active path
        $activePages = [];

        if ($this->requestHelper->hasMainRequest()) {
            $request = $this->requestHelper->getMainRequest();

            // try to find a page matching exactly the request uri
            $activePages = $this->findActivePages($navigation, 'uri', $request->getRequestUri());

            if (empty($activePages)) {
                // try to find a page matching the path info
                $activePages = $this->findActivePages($navigation, 'uri', $request->getPathInfo());
            }
        }

        if ($activeDocument instanceof Document) {
            if (empty($activePages)) {
                // use the provided pimcore document
                $activePages = $this->findActivePages($navigation, 'realFullPath', $activeDocument->getRealFullPath());
            }

            if (empty($activePages)) {
                // find by link target
                $activePages = $this->findActivePages($navigation, 'uri', $activeDocument->getFullPath());
            }
        }

        // cleanup active pages from links
        // pages have priority, if we don't find any active page, we use all we found
        $tmpPages = [];
        foreach ($activePages as $page) {
            if ($page instanceof DocumentPage && $page->getDocumentType() !== 'link') {
                $tmpPages[] = $page;
            }
        }
        if (count($tmpPages)) {
            $activePages = $tmpPages;
        }

        if (!empty($activePages)) {
            // we found an active document, so we can build the active trail by getting respectively the parent
            foreach ($activePages as $activePage) {
                $this->addActiveCssClasses($activePage, true);
            }
        } elseif ($activeDocument instanceof Document) {
            // we didn't find the active document, so we try to build the trail on our own
            $allPages = new \RecursiveIteratorIterator($navigation, \RecursiveIteratorIterator::SELF_FIRST);

            foreach ($allPages as $page) {
                $activeTrail = false;

                if ($page instanceof Url && $page->getUri()) {
                    if (str_starts_with($activeDocument->getRealFullPath(), $page->getUri() . '/')) {
                        $activeTrail = true;
                    } elseif (
                        $page instanceof DocumentPage &&
                        $page->getDocumentType() === 'link' &&
                        str_starts_with($activeDocument->getFullPath(), $page->getUri() . '/')
                    ) {
                        $activeTrail = true;
                    }
                }

                if ($activeTrail) {
                    $page->setActive(true);
                    $page->setClass($page->getClass() . ' active active-trail');
                }
            }
        }

        return $navigation;
    }

    /**
     * @internal
     *
     * @param Container $navigation navigation container to iterate
     * @param string $property name of property to match against
     * @param string $value value to match property against
     *
     * @return Page[]
     */
    protected function findActivePages(Container $navigation, string $property, string $value): array
    {
        $filterByPrefix = new PrefixRecursiveFilterIterator($navigation, $property, $value);
        $flatten = new \RecursiveIteratorIterator($filterByPrefix, \RecursiveIteratorIterator::SELF_FIRST);
        $filterMatches = new \CallbackFilterIterator($flatten, static fn (Page $page): bool => $page->get($property) === $value);

        return iterator_to_array($filterMatches, false);
    }

    /**
     * @internal
     *
     * @param Page $page
     * @param bool $isActive
     *
     * @throws \Exception
     */
    protected function addActiveCssClasses(Page $page, $isActive = false)
    {
        $page->setActive(true);

        $parent = $page->getParent();
        $isRoot = false;
        $classes = '';

        if ($parent instanceof DocumentPage) {
            $this->addActiveCssClasses($parent);
        } else {
            $isRoot = true;
        }

        $classes .= ' active';

        if (!$isActive) {
            $classes .= ' active-trail';
        }

        if ($isRoot && $isActive) {
            $classes .= ' mainactive';
        }

        $page->setClass($page->getClass() . $classes);
    }

    /**
     * @param string $pageClass
     *
     * @return $this
     */
    public function setPageClass(string $pageClass)
    {
        $this->pageClass = $pageClass;

        return $this;
    }

    /**
     * Returns the name of the pageclass
     *
     * @return String
     */
    public function getPageClass()
    {
        return $this->pageClass;
    }

    /**
     * @param Document $parentDocument
     *
     * @return Document[]
     */
    protected function getChildren(Document $parentDocument): array
    {
        // the intention of this function is mainly to be overridden in order to customize the behavior of the navigation
        // e.g. for custom filtering and other very specific use-cases
        return $parentDocument->getChildren();
    }

    /**
     * @internal
     *
     * @param Document $parentDocument
     * @param bool $isRoot
     * @param callable $pageCallback
     * @param array $parents
     * @param int|null $maxDepth
     *
     * @return Page[]
     *
     * @throws \Exception
     */
    protected function buildNextLevel($parentDocument, $isRoot = false, $pageCallback = null, $parents = [], $maxDepth = null)
    {
        $this->currentLevel++;
        $pages = [];
        $childs = $this->getChildren($parentDocument);
        $parents[$parentDocument->getId()] = $parentDocument;

        if (!is_array($childs)) {
            return $pages;
        }

        foreach ($childs as $child) {
            $classes = '';

            if ($child instanceof Document\Hardlink) {
                $child = Document\Hardlink\Service::wrap($child);
                if (!$child) {
                    continue;
                }
            }

            // infinite loop detection, we use array keys here, because key lookups are much faster
            if (isset($parents[$child->getId()])) {
                Logger::critical('Navigation: Document with ID ' . $child->getId() . ' would produce an infinite loop -> skipped, parent IDs (' . implode(',', array_keys($parents)) . ')');

                continue;
            }

            if (($child instanceof Document\Folder || $child instanceof Document\Page || $child instanceof Document\Link) && $child->getProperty('navigation_name')) {
                $path = $child->getFullPath();
                if ($child instanceof Document\Link) {
                    $path = $child->getHref();
                }

                /** @var DocumentPage $page */
                $page = new $this->pageClass();
                if (!$child instanceof Document\Folder) {
                    $page->setUri($path . $child->getProperty('navigation_parameters') . $child->getProperty('navigation_anchor'));
                }
                $page->setLabel($child->getProperty('navigation_name'));
                $page->setActive(false);
                $page->setId($this->htmlMenuIdPrefix . $child->getId());
                $page->setClass($child->getProperty('navigation_class'));
                $page->setTarget($child->getProperty('navigation_target'));
                $page->setTitle($child->getProperty('navigation_title'));
                $page->setAccesskey($child->getProperty('navigation_accesskey'));
                $page->setTabindex($child->getProperty('navigation_tabindex'));
                $page->setRelation($child->getProperty('navigation_relation'));
                $page->setDocument($child);

                if ($child->getProperty('navigation_exclude') || !$child->getPublished()) {
                    $page->setVisible(false);
                }

                if ($isRoot) {
                    $classes .= ' main';
                }

                $page->setClass($page->getClass() . $classes);

                if ($child->hasChildren() && (!$maxDepth || $maxDepth > $this->currentLevel)) {
                    $childPages = $this->buildNextLevel($child, false, $pageCallback, $parents, $maxDepth);
                    $page->setPages($childPages);
                }

                if ($pageCallback instanceof \Closure) {
                    $pageCallback($page, $child);
                }

                $this->navCacheTags[] = $page->getDocument()->getCacheTag();

                $pages[] = $page;
            }
        }

        $this->currentLevel--;

        return $pages;
    }
}
