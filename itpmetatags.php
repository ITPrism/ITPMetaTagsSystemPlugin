<?php
/**
 * @package      ITPMeta
 * @subpackage   Plugins
 * @author       Todor Iliev
 * @copyright    Copyright (C) 2017 Todor Iliev <todor@itprism.com>. All rights reserved.
 * @license      GNU General Public License version 3 or later; see LICENSE.txt
 */

use Joomla\Utilities\ArrayHelper;
use Joomla\String\StringHelper;
use Itpmeta\Url\UrlHelper;

// no direct access
defined('_JEXEC') or die;

jimport('Prism.init');
jimport('Itpmeta.init');

/**
 * ITPMeta Tags Plugin
 *
 * @package        ITPMeta
 * @subpackage     Plugins
 */
class plgSystemItpmetaTags extends JPlugin
{
    /**
     * @var JApplicationSite
     */
    protected $app;

    /**
     * @var Itpmeta\Url\Uri
     */
    protected $uri;

    protected $supportedComponents = array();

    /**
     * Constructor
     *
     * @param   stdClass  &$subject  The object to observe
     * @param   array   $config    An optional associative array of configuration settings.
     *                             Recognized key values include 'name', 'group', 'params', 'language'
     *                             (this list is not meant to be comprehensive).
     *
     * @since   1.5
     */
    public function __construct(&$subject, $config = array())
    {
        parent::__construct($subject, $config);

        $this->supportedComponents = array(
            'com_k2', 'com_content', 'com_cobalt', 'com_crowdfunding',
            'com_userideas', 'com_socialcommunity', 'com_virtuemart', 'com_eshop'
        );
    }

    /**
     * Get clean URI.
     *
     * @throws \Exception
     *
     * @return Itpmeta\Url\Uri
     */
    protected function getUri()
    {
        $container       = Prism\Container::getContainer();
        $containerHelper = new Itpmeta\Container\Helper();

        $keys = array(
            'uri' => UrlHelper::getCleanUri()
        );

        return $containerHelper->fetchUri($container, $keys);
    }

    private function isRestricted()
    {
        if ($this->app->isAdmin()) {
            return true;
        }

        $document = JFactory::getDocument();
        /** @var $document JDocumentHTML */

        $type = $document->getType();
        if (strcmp('html', $type) !== 0) {
            return true;
        }

        // It works only for GET request
        $method = $this->app->input->getMethod();
        if (strcmp('GET', $method) !== 0) {
            return true;
        }

        // Check component enabled
        if (!JComponentHelper::isEnabled('com_itpmeta')) {
            return true;
        }

        // Check for disabled component.
        $option = $this->app->input->getCmd('option');
        if (!in_array($option, $this->supportedComponents, true)) {
            return true;
        } else {
            $isEnabled = (bool)$this->params->get($option);
            if (!$isEnabled) {
                return true;
            }
        }

        // The URL missing, unpublished or you do not have to update it.
        $this->uri = $this->getUri();
        if (!$this->uri->getId() or !$this->uri->isAutoupdate() or !$this->uri->isPublished()) {
            return true;
        }

        // Check the allowed period for auto-update.
        $period   = (int)$this->params->get('autoupdate_period');
        if ($period > 0) {
            $checked     = $this->uri->getCheckDate();
            $today       = new DateTime();
            $checkedDate = new DateTime($checked);

            $diff = (int)$checkedDate->diff($today)->format('%a');
            if ($period > $diff) {
                return true;
            }
        }

        return false;
    }

    public function onAfterDispatch()
    {
        // Check for restrictions
        if ($this->isRestricted()) {
            return;
        }

        $options = array(
            'option'            => $this->app->input->getCmd('option'),
            'view'              => $this->app->input->getCmd('view'),
            'task'              => $this->app->input->getCmd('task'),
            'menu_item_id'      => $this->app->input->getInt('Itemid'),
            'generate_metadesc' => (bool)$this->params->get('generate_metadesc', 1),
            'extract_image'     => (bool)$this->params->get('extract_image', 0),
        );

        // Get data about category or item.
        $data = $this->getData($options);

        // Generate tags using current information from extension.
        $tags = $this->prepareTags($data);

        // Update tags
        if (count($tags) > 0) {
            $this->updateTags($tags);

            $uriString  = UrlHelper::getCleanUri();
            $hash       = Prism\Utilities\StringHelper::generateMd5Hash(Itpmeta\Constants::CACHE_URI, $uriString);

            $cache      = JFactory::getCache('com_itpmeta', '');
            $cache->remove($hash, 'com_itpmeta');
        }

        // Update the date when the URI has been checked for modifications.
        $this->uri->updateCheckDate();
    }

    /**
     * This method creates an object based on extension name,
     * and loads data about the page based on view options.
     *
     * @param array $options It contains values from request - option, view, task and Itemid.
     *
     * @throws \Exception
     * @return array
     */
    public function getData(array $options)
    {
        $data = array();

        $filter = JFilterInput::getInstance();

        // Get extension name.
        $extensionName = StringHelper::strtolower(str_replace('com_', '', $options['option']));
        $extensionName = $filter->clean($extensionName);

        // Create an object.
        $extensionClass = 'Itpmeta\\Extension\\' . StringHelper::ucfirst($extensionName);

        if (class_exists($extensionClass)) {
            $extension = new $extensionClass($options);
            /** @var $extension Itpmeta\Extension\Base */

            $extension->setDb(JFactory::getDbo());

            // Parse the URL
            $uri           = clone UrlHelper::getUri();

            $router        = JApplicationSite::getRouter();
            $parsedOptions = $router->parse($uri);

            // Load content data and generate tags.
            $data = $extension->getData($parsedOptions);
        }

        return (array)$data;
    }

    /**
     * Prepare tags using data from current extension.
     *
     * @param array $data
     *
     * @throws \Exception
     * @return array
     */
    private function prepareTags($data)
    {
        $uri = UrlHelper::getUri();
        $url = $uri->toString(array('scheme', 'host', 'port', 'path', 'query'));

        $filter = JFilterInput::getInstance();
        $url    = $filter->clean($url);

        $tags = array();

        // Open Graph Tags
        $this->prepareOpenGraphTags($tags, $data, $url);

        // SEO tags
        $this->prepareSeoTags($tags, $url);

        // Twitter tags
        $this->prepareTwitterTags($tags, $data, $url);

        // Dublin Core tags
        $this->prepareDublinCoreTags($tags, $data, $url);

        return (array)$tags;
    }

    /**
     * Update old data with new one.
     *
     * @param array $tags Tags with data from current extension.
     *
     * @throws \Exception
     */
    private function updateTags($tags)
    {
        $newTags    = array();
        $updateTags = array();

        $urlId   = $this->uri->getId();

        // Load the tags of the URI that exists.
        $uriTags = new Itpmeta\Tag\Tags(JFactory::getDbo());
        $uriTags->load(['uri_id' => $urlId]);

        // Split new tags from updated
        foreach ($tags as $name => $newTag) {
            if (!$newTag or !is_array($newTag)) {
                continue;
            }

            // Get the tag of extension and compare them with defined ones ( by user ).
            $currentTag = $uriTags->getTag($name);
            
            if ($currentTag !== null) { // Compare old values with the new ones
                
                // Update tags with new value that comes from article
                // Example: If you change article title,
                // the system will add the new content and will notify the administrator
                if ($this->isNew($currentTag, $newTag)) {
                    $currentTag->setTag(ArrayHelper::getValue($newTag, 'tag', '', 'string'));
                    $currentTag->setContent(ArrayHelper::getValue($newTag, 'content', '', 'string'));
                    $currentTag->setOutput(ArrayHelper::getValue($newTag, 'output', '', 'string'));

                    $updateTags[] = $currentTag->toArray(true);
                }
            } else { // Insert new tags

                $content = ArrayHelper::getValue($newTag, 'content', '', 'string');
                $tag     = new Itpmeta\Tag\Tag();

                $tag->bind($newTag);
                $tag->setName($name);
                $tag->setContent($content);
                $tag->setUrlId($urlId);

                $newTags[] = $tag->toArray(true);
            }
        }

        $this->storeTags($newTags, $updateTags);

        unset($newTags, $updateTags);
    }

    /**
     * Insert and update tags.
     *
     * @param array $newTags
     * @param array $updateTags
     *
     * @throws \RuntimeException
     */
    protected function storeTags($newTags, $updateTags)
    {
        // Insert the new tags in one query.
        if (count($newTags) > 0) {
            $queryData = array();

            $db    = JFactory::getDbo();

            foreach ($newTags as $data) {
                unset($data['id'], $data['ordering']);
                $queryData[] = implode(',', (array)$db->quote($data));
            }

            $query = $db->getQuery(true);
            $query
                ->insert($db->quoteName('#__itpm_tags'))
                ->columns($db->quoteName(['name', 'title', 'type', 'tag', 'content', 'output', 'url_id']))
                ->values($queryData);

            $db->setQuery($query);
            $db->execute();

            unset($queryData);
        }
        
        if (count($updateTags) > 0) {
            $keys       = array();
            $queryData  = array();

            $db    = JFactory::getDbo();

            foreach ($updateTags as $data) {
                $keys[]      = (int)$data['id'];
                $queryData[] = implode(',', (array)$db->quote($data));
            }

            if (count($keys) > 0) {
                // Delete old records.
                $query = $db->getQuery(true);
                $query
                    ->delete($db->quoteName('#__itpm_tags'))
                    ->where($db->quoteName('id') .' IN ('.implode(',', $keys). ')');

                $db->setQuery($query);
                $db->execute();

                // Insert new ones.
                $query = $db->getQuery(true);
                $query
                    ->insert($db->quoteName('#__itpm_tags'))
                    ->columns($db->quoteName(['id', 'name', 'title', 'type', 'tag', 'content', 'output', 'ordering', 'url_id']))
                    ->values($queryData);

                $db->setQuery($query);
                $db->execute();
            }

            unset($queryData, $keys);
        }
    }

    /**
     * Compare old tags with the new ones
     *
     * @param Itpmeta\Tag\Tag $oldTag
     * @param array      $newTag
     *
     * @throws \InvalidArgumentException
     * @return bool
     */
    private function isNew($oldTag, array $newTag)
    {
        // Compare tag string
        $oldTagString = $oldTag->getTag();
        $newTagString = ArrayHelper::getValue($newTag, 'tag', '', 'string');

        $oldTagString = md5($oldTagString);
        $newTagString = md5($newTagString);

        if (strcmp($oldTagString, $newTagString) !== 0) {
            return true;
        }

        // Compare contents
        $oldContent = StringHelper::trim($oldTag->getContent());
        $newContent = StringHelper::trim(ArrayHelper::getValue($newTag, 'content', '', 'string'));

        if ($oldContent === '$oldContent' and $newContent !== '') {
            return true;
        }

        // Compare output string.
        // If source content has been updated,
        // then you should replace the old with the new one
        if ($oldContent !== '' and $newContent !== '') {
            $oldContent = md5($oldContent);
            $newContent = md5($newContent);

            if (strcmp($oldContent, $newContent) !== 0) {
                return true;
            }
        }

        return false;
    }

    private function prepareOpenGraphTags(array &$tags, array $data, $url)
    {
        if ($this->params->get('ogtitle')) {
            $title = ArrayHelper::getValue($data, 'title', '', 'string');
            $title = StringHelper::trim(htmlentities($title, ENT_QUOTES, 'UTF-8'));

            if ($title !== '') {
                $tag = new Itpmeta\Tag\Custom();
                $tag->load('ogtitle');

                $tag->setContent($title);
                $tags['ogtitle'] = $tag->toArray();
            }
        }

        if ($this->params->get('ogdescription')) {
            $metadesc = ArrayHelper::getValue($data, 'metadesc', '', 'string');
            $metadesc = StringHelper::trim(htmlentities($metadesc, ENT_QUOTES, 'UTF-8'));

            if ($metadesc !== '') {
                $tag = new Itpmeta\Tag\Custom();
                $tag->load('ogdescription');

                $tag->setContent($metadesc);
                $tags['ogdescription'] = $tag->toArray();
            }
        }

        if ($this->params->get('ogimage')) {
            $image = $this->prepareImage($data);
            if ($image !== '') {
                $tag = new Itpmeta\Tag\Custom();
                $tag->load('ogimage');

                $tag->setContent($image);
                $tags['ogimage'] = $tag->toArray();
            }
        }

        if ($this->params->get('ogurl')) {
            $tag = new Itpmeta\Tag\Custom();
            $tag->load('ogurl');

            $tag->setContent($url);
            $tags['ogurl'] = $tag->toArray();
        }

        if ($this->params->get('ogarticle_published_time')) {
            $created = ArrayHelper::getValue($data, 'created', '', 'string');
            if ($created !== '') {
                $tag = new Itpmeta\Tag\Custom();
                $tag->load('ogarticle_published_time');

                $tag->setContent($created);
                $tags['ogarticle_published_time'] = $tag->toArray();
            }
        }

        if ($this->params->get('ogarticle_modified_time')) {
            $modified = ArrayHelper::getValue($data, 'modified', '', 'string');

            if ($modified !== '') {
                $tag = new Itpmeta\Tag\Custom();
                $tag->load('ogarticle_modified_time');

                $tag->setContent($modified);
                $tags['ogarticle_modified_time'] = $tag->toArray();
            }
        }
    }

    private function prepareTwitterTags(array &$tags, array $data, $url)
    {
        if ($this->params->get('twitter_card')) {
            $tag = new Itpmeta\Tag\Custom();
            $tag->load($this->params->get('twitter_card'));

            $tags['twitter_card'] = $tag->toArray();
        }

        if ($this->params->get('twitter_card_title')) {
            $title = ArrayHelper::getValue($data, 'title', '', 'string');
            $title = StringHelper::trim(htmlentities($title, ENT_QUOTES, 'UTF-8'));

            if ($title !== '') {
                $tag = new Itpmeta\Tag\Custom();
                $tag->load('twitter_card_title');

                $tag->setContent($title);
                $tags['twitter_card_title'] = $tag->toArray();
            }
        }

        if ($this->params->get('twitter_card_description')) {
            $metadesc = ArrayHelper::getValue($data, 'metadesc', '', 'string');
            $metadesc = StringHelper::trim(htmlentities($metadesc, ENT_QUOTES, 'UTF-8'));

            if ($metadesc !== '') {
                $tag = new Itpmeta\Tag\Custom();
                $tag->load('twitter_card_description');

                $tag->setContent($metadesc);
                $tags['twitter_card_description'] = $tag->toArray();
            }
        }

        if ($this->params->get('twitter_card_image')) {
            $image = $this->prepareImage($data);

            if ($image !== '') {
                $tag = new Itpmeta\Tag\Custom();
                $tag->load('twitter_card_image');

                $tag->setContent($image);
                $tags['twitter_card_image'] = $tag->toArray();
            }
        }

        if ($this->params->get('twitter_card_image_alt')) {
            $imageAlt = ArrayHelper::getValue($data, 'image_alt', '', 'string');

            if ($imageAlt !== '') {
                $tag = new Itpmeta\Tag\Custom();
                $tag->load('twitter_card_image_alt');

                $tag->setContent($imageAlt);
                $tags['twitter_card_image_alt'] = $tag->toArray();
            }
        }

        if ($this->params->get('twitter_card_url')) {
            $tag = new Itpmeta\Tag\Custom();
            $tag->load('twitter_card_url');

            $tag->setContent($url);
            $tags['twitter_card_url'] = $tag->toArray();
        }
    }

    private function prepareSeoTags(array &$tags, $url)
    {
        if ($this->params->get('seo_canonical')) {
            $tag = new Itpmeta\Tag\Custom();
            $tag->load('seo_canonical');

            $tag->setContent($url);
            $tags['seo_canonical'] = $tag->toArray();
        }
    }

    protected function prepareImage(array $data)
    {
        $defaultImage = (string)$this->params->get('default_image', '');
        $image        = ArrayHelper::getValue($data, 'image', '', 'string');

        if ($image !== '') {
            if (0 !== strpos($image, 'http')) { // Not full link. Example: /images/image.png
                $image = JUri::root() . $image;
            }
        } else { // Default image

            if ($defaultImage !== '') {
                $image = $defaultImage;
                if (0 !== strpos($defaultImage, 'http')) { // Not full link. Example: /images/image.png
                    $image = JUri::root() . $defaultImage;
                }
            }
        }

        return $image;
    }

    private function prepareDublinCoreTags(array &$tags, array $data, $url)
    {
        if ($this->params->get('dublincore_title')) {
            $title = ArrayHelper::getValue($data, 'title', '', 'string');
            $title = StringHelper::trim(htmlentities($title, ENT_QUOTES, 'UTF-8'));

            if ($title !== '') {
                $tag = new Itpmeta\Tag\Custom();
                $tag->load('dublincore_title');

                $tag->setContent($title);
                $tags['dublincore_title'] = $tag->toArray();
            }
        }

        if ($this->params->get('dublincore_description')) {
            $metadesc = ArrayHelper::getValue($data, 'metadesc', '', 'string');
            $metadesc = StringHelper::trim(htmlentities($metadesc, ENT_QUOTES, 'UTF-8'));

            if ($metadesc !== '') {
                $tag = new Itpmeta\Tag\Custom();
                $tag->load('dublincore_description');

                $tag->setContent($metadesc);
                $tags['dublincore_description'] = $tag->toArray();
            }
        }

        if ($this->params->get('dublincore_url')) {
            $tag = new Itpmeta\Tag\Custom();
            $tag->load('dublincore_url');

            $tag->setContent($url);
            $tags['dublincore_url'] = $tag->toArray();
        }

        if ($this->params->get('dublincore_published_time')) {
            $created = ArrayHelper::getValue($data, 'created', '', 'string');

            if ($created !== '') {
                $tag = new Itpmeta\Tag\Custom();
                $tag->load('dublincore_published_time');

                $tag->setContent($created);
                $tags['dublincore_published_time'] = $tag->toArray();
            }
        }

        if ($this->params->get('dublincore_modified_time')) {
            $modified = ArrayHelper::getValue($data, 'modified', '', 'string');

            if ($modified !== '') {
                $tag = new Itpmeta\Tag\Custom();
                $tag->load('dublincore_modified_time');

                $tag->setContent($modified);
                $tags['dublincore_modified_time'] = $tag->toArray();
            }
        }
    }
}
