<?php
/*
 * This file is part of the Fred package.
 *
 * Copyright (c) MODX, LLC
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Fred\Endpoint\Ajax;

use Fred\Utils;
use MODX\Revolution\modContext;
use MODX\Revolution\modResource;
use MODX\Revolution\modTemplateVar;
use MODX\Revolution\modTemplateVarTemplate;

class SaveContent extends Endpoint
{
    protected $taggerLoaded = false;
    protected $tagger = null;

    /** @var modResource */
    protected $object = null;

    /** @var modContext */
    protected $context = null;

    public function process(): string
    {
        if (!isset($this->body['id'])) {
            return $this->failure($this->modx->lexicon('fred.fe.err.resource_ns_id'));
        }

        $id = (int)$this->body['id'];

        if (!$this->verifyClaim('resource', $id)) {
            return $this->failure($this->modx->lexicon('fred.fe.err.resource_invalid_id'));
        }

        if (!isset($this->body['data'])) {
            return $this->failure($this->modx->lexicon('fred.fe.err.resource_ns_data'));
        }

        $context = $this->getClaim('context');
        $context = !empty($context) ? $context : 'web';

        $this->context = $this->modx->getObject(modContext::class, ['key' => $context]);
        if (empty($this->context)) {
            return $this->failure($this->modx->lexicon('fred.fe.err.resource_context_nf'));
        }

        $this->object = $this->modx->getObject(modResource::class, $id);
        if (!$this->object instanceof modResource) {
            return $this->failure($this->modx->lexicon('fred.fe.err.resource_nf_id', ['id' => $id]));
        }

        if (!$this->modx->hasPermission('save_document') || !$this->object->checkPolicy('save')) {
            return $this->failure($this->modx->lexicon('fred.fe.err.permission_denied'));
        }

        $data = $this->object->getProperty('data', 'fred');
        if (!empty($data['fingerprint'])) {
            if (empty($this->body['fingerprint'])) {
                return $this->failure('No fingerprint was provided.');
            }

            if ($data['fingerprint'] !== $this->body['fingerprint']) {
                return $this->failure($this->modx->lexicon('fred.fe.err.resource_stale'));
            }
        }

        $this->loadTagger();

        $parser = $this->modx->getParser();

        if (isset($this->body['content'])) {
            $content = $this->body['content'];
            $content = Utils::htmlDecodeTags($content, $parser);

            $this->object->setContent($content);
        }

        $c = $this->modx->newQuery(modTemplateVar::class);
        $c->leftJoin(modTemplateVarTemplate::class, 'TemplateVarTemplates');

        $c->where([
            'TemplateVarTemplates.templateid' => $this->object->get('template')
        ]);

        /** @var modTemplateVar[] $tvs */
        $tvs = $this->modx->getIterator(modTemplateVar::class, $c);
        $mTypes = $this->modx->getOption('manipulatable_url_tv_output_types', null, 'image,file');
        $mTypes = explode(',', $mTypes);
        foreach ($tvs as $tv) {
            $tvName = $tv->get('name');

            if (isset($this->body[$tvName])) {
                $tvContent = $this->body[$tvName];
                $tvContent = Utils::htmlDecodeTags($tvContent, $parser);
                if (in_array($tv->type, $mTypes, true)) {
                    $this->object->setTVValue($tvName, $this->reversePreparedOutput($tv, $tvContent, $this->object));
                } else {
                    $this->object->setTVValue($tvName, $tvContent);
                }
            }
        }

        if (isset($this->body['pageSettings']['introtext'])) {
            $this->object->set('introtext', $this->body['pageSettings']['introtext']);
        }

        if (isset($this->body['pageSettings']['description'])) {
            $this->object->set('description', $this->body['pageSettings']['description']);
        }

        if (isset($this->body['pageSettings']['pagetitle'])) {
            if (empty($this->body['pageSettings']['pagetitle'])) {
                return $this->failure($this->modx->lexicon('fred.fe.err.pagetitle_ns'));
            }

            $this->object->set('pagetitle', $this->body['pageSettings']['pagetitle']);
        }

        if (isset($this->body['pageSettings']['menutitle'])) {
            $this->object->set('menutitle', $this->body['pageSettings']['menutitle']);
        }

        if (isset($this->body['pageSettings']['longtitle'])) {
            $this->object->set('longtitle', $this->body['pageSettings']['longtitle']);
        }

        if (isset($this->body['pageSettings']['menuindex'])) {
            $this->object->set('menuindex', (int)$this->body['pageSettings']['menuindex']);
        }

        if (isset($this->body['pageSettings']['hidemenu'])) {
            $this->object->set('hidemenu', (bool)$this->body['pageSettings']['hidemenu']);
        }

        if (isset($this->body['pageSettings']['deleted'])) {
            $currentValue = (bool)$this->object->get('deleted');
            $incomingValue = (bool)$this->body['pageSettings']['deleted'];

            if ($incomingValue) {
                if (!$this->modx->hasPermission('delete_document') || !$this->object->checkPolicy('delete')) {
                    $incomingValue = $currentValue;
                }
            } else {
                if (!$this->modx->hasPermission('undelete_document') || !$this->object->checkPolicy('undelete')) {
                    $incomingValue = $currentValue;
                }
            }

            if ($incomingValue !== $currentValue) {
                $this->object->set('deleted', $incomingValue);
                $this->object->set('deletedon', $incomingValue ? time() : false);
                $this->object->set('deletedby', $incomingValue ? $this->modx->user->get('id') : 0);
            }
        }

        if (isset($this->body['pageSettings']['publishon'])) {
            $currentValue = (int)$this->object->get('pub_date');
            $incomingValue = (int)$this->body['pageSettings']['publishon'];

            if (!$this->modx->hasPermission('publish_document') || !$this->object->checkPolicy('publish')) {
                $incomingValue = $currentValue;
            }

            $this->object->set('pub_date', $incomingValue);
        }

        if (isset($this->body['pageSettings']['unpublishon'])) {
            $currentValue = (int)$this->object->get('unpub_date');
            $incomingValue = (int)$this->body['pageSettings']['unpublishon'];

            if (!$this->modx->hasPermission('unpublish_document') || !$this->object->checkPolicy('unpublish')) {
                $incomingValue = $currentValue;
            }
            $this->object->set('unpub_date', $incomingValue);
        }

        $uriChanged = false;
        if (isset($this->body['pageSettings']['alias'])) {
            $pagetitle = isset($this->body['pageSettings']['pagetitle']) ? $this->body['pageSettings']['pagetitle'] : '';
            $aliasSet = $this->setAlias($this->body['pageSettings']['alias'], $pagetitle);

            if ($aliasSet !== true) {
                return $this->failure($aliasSet);
            }

            $uriChanged = $this->object->isDirty('alias');
        }

        if (isset($this->body['pageSettings']['published'])) {
            $currentValue = (bool)$this->object->get('published');
            $incomingValue = (bool)$this->body['pageSettings']['published'];

            if ($incomingValue) {
                if (!$this->modx->hasPermission('publish_document') || !$this->object->checkPolicy('publish')) {
                    $incomingValue = $currentValue;
                }
            } else {
                if (!$this->modx->hasPermission('unpublish_document') || !$this->object->checkPolicy('unpublish')) {
                    $incomingValue = $currentValue;
                }
            }

            if ($incomingValue !== $currentValue) {
                if ($incomingValue) {
                    if (!empty($this->body['pageSettings']['publishedon'])) {
                        $publishedOn = (int)$this->body['pageSettings']['publishedon'];
                    } else {
                        $publishedOn = time();
                    }
                } else {
                    $publishedOn = false;
                }

                $this->object->set('published', (bool)$this->body['pageSettings']['published']);
                $this->object->set('publishedon', $publishedOn);
                $this->object->set('publishedby', $incomingValue ? $this->modx->user->get('id') : 0);
            } else {
                if (isset($this->body['pageSettings']['publishedon'])) {
                    $this->object->set('publishedon', (int)$this->body['pageSettings']['publishedon']);
                }
            }
        }

        if (!empty($this->body['plugins'])) {
            $this->object->setProperty('plugins', $this->body['plugins'], 'fred');
        } else {
            $this->object->setProperty('plugins', (object)[], 'fred');
        }

        $this->object->setProperty('data', $this->body['data'], 'fred');

        $this->object->set('editedon', time());
        $this->object->set('editedby', $this->modx->user->get('id'));

        $this->handleTagger($this->object);

        $this->body['data']['fingerprint'] = Utils::resourceFingerprint($this->object);
        $this->object->setProperty('data', $this->body['data'], 'fred');

        $beforeSave = $this->modx->invokeEvent('FredOnBeforeFredResourceSave', [
            'id' => $this->object->get('id'),
            'resource' => &$this->object
        ]);

        if (is_array($beforeSave)) {
            $preventSave = false;

            foreach ($beforeSave as $msg) {
                if (!empty($msg)) {
                    $preventSave .= $msg . " ";
                }
            }
        } else {
            $preventSave = $beforeSave;
        }

        if ($preventSave !== false) {
            return $this->failure($preventSave);
        }

        if (isset($this->body['pageSettings']['tvs']) && is_array($this->body['pageSettings']['tvs'])) {
            foreach ($tvs as $tv) {
                $tvName = $tv->get('name');
                if (isset($this->body['pageSettings']['tvs'][$tvName])) {
                    $tvContent = $this->body['pageSettings']['tvs'][$tvName];
                    $tvContent = Utils::htmlDecodeTags($tvContent, $parser);
                    if (in_array($tv->type, $mTypes, true)) {
                        $this->object->setTVValue($tvName, $this->reversePreparedOutput($tv, $tvContent, $this->object));
                    } else {
                        $this->object->setTVValue($tvName, $tvContent);
                    }
                }
            }
        }

        $saved = $this->object->save();

        if (!$saved) {
            return $this->failure($this->modx->lexicon('fred.fe.err.resource_save'));
        }

        $this->modx->getCacheManager()->refresh();
        $this->modx->setOption('cache_alias_map', false);

        $this->modx->invokeEvent('FredOnFredResourceSave', [
            'id' => $this->object->get('id'),
            'resource' => &$this->object
        ]);

        $response = [
            'message' => $this->modx->lexicon('fred.fe.pages.updated'),
            'fingerprint' => $this->body['data']['fingerprint'],
            'publishedon' => $this->object->publishedon,
            'alias' => $this->object->alias,
        ];

        if ($uriChanged) {
            $response['url'] = $this->modx->makeUrl($this->object->get('id'), $this->object->get('context_key'), '', 'full');
        }

        return $this->success($response);
    }

    /**
     * @param modResource $resource
     */
    protected function handleTagger($resource)
    {
        if (!$this->taggerLoaded) {
            return;
        }

        /** @var \Tagger\Model\TaggerGroup[] $groups */
        $groups = $this->modx->getIterator('Tagger\\Model\\TaggerGroup');
        foreach ($groups as $group) {
            $showForTemplates = $group->show_for_templates;
            $showForTemplates = \Tagger\Utils::explodeAndClean($showForTemplates);
            $showForTemplates = array_flip($showForTemplates);

            $showForContexts = $group->show_for_contexts;
            $showForContexts = \Tagger\Utils::explodeAndClean($showForContexts);
            $showForContexts = array_flip($showForContexts);

            if (!isset($showForTemplates[$resource->template])) {
                continue;
            }

            if (!empty($showForContexts) && !isset($showForContexts[$resource->context_key])) {
                continue;
            }

            $oldTagsQuery = $this->modx->newQuery('Tagger\\Model\\TaggerTagResource');
            $oldTagsQuery->leftJoin('TaggerTag', 'Tag');
            $oldTagsQuery->where(['resource' => $resource->id, 'Tag.group' => $group->id]);
            $oldTagsQuery->select($this->modx->getSelectColumns('Tagger\\Model\\TaggerTagResource', 'TaggerTagResource', '', ['tag']));

            $oldTagsQuery->prepare();
            $oldTagsQuery->stmt->execute();

            $oldTags = $oldTagsQuery->stmt->fetchAll(\PDO::FETCH_COLUMN, 0);
            $oldTags = array_flip($oldTags);

            if (isset($this->body['pageSettings']['tagger']['tagger-' . $group->id])) {
                $tags = $this->body['pageSettings']['tagger']['tagger-' . $group->id];
                $tags = array_map('trim', $tags);
                $tags = array_keys(array_flip($tags));

                if ($group->tag_limit > 0) {
                    $tags = array_slice($tags, 0, $group->tag_limit);
                }
            } else {
                continue;
            }

            foreach ($tags as $tag) {
                /** @var \Tagger\Model\TaggerTag $tagObject */
                $tagObject = $this->modx->getObject('Tagger\\Model\\TaggerTag', array('tag' => $tag, 'group' => $group->id));
                if ($tagObject) {
                    $existsRelation = $this->modx->getObject('Tagger\\Model\\TaggerTagResource', array('tag' => $tagObject->id, 'resource' => $resource->id));
                    if ($existsRelation) {
                        if (isset($oldTags[$existsRelation->tag])) {
                            unset($oldTags[$existsRelation->tag]);
                        }
                        continue;
                    }
                }
                if (!$tagObject) {
                    if (!$group->allow_new) {
                        continue;
                    }

                    $tagObject = $this->modx->newObject('Tagger\\Model\\TaggerTag');
                    $tagObject->set('tag', $tag);
                    $tagObject->addOne($group, 'Group');
                    $tagObject->save();
                }

                /** @var \Tagger\Model\TaggerTagResource $relationObject */
                $relationObject = $this->modx->newObject('Tagger\\Model\\TaggerTagResource');
                $relationObject->set('tag', $tagObject->id);
                $relationObject->set('resource', $resource->id);
                $relationObject->save();
            }

            if (count($oldTags) > 0) {
                $oldTags = array_keys($oldTags);
                $this->modx->removeCollection('TaggerTagResource', array(
                    'tag:IN' => $oldTags,
                    'AND:resource:=' => $resource->id
                ));
            }

            if ($group->remove_unused) {
                $c = $this->modx->newQuery('Tagger\\Model\\TaggerTagResource');
                $c->select($this->modx->getSelectColumns('Tagger\\Model\\TaggerTagResource', 'TaggerTagResource', '', array('tag')));
                $c->prepare();
                $c->stmt->execute();
                $IDs = $c->stmt->fetchAll(\PDO::FETCH_COLUMN, 0);
                $IDs = array_keys(array_flip($IDs));
                if (count($IDs) > 0) {
                    $this->modx->removeCollection('TaggerTag', array('id:NOT IN' => $IDs, 'group' => $group->id));
                }
            }
        }
    }

    protected function setAlias($alias, $pageTitle): bool
    {
        $autoGenerated = false;

        $pageTitle = empty($pageTitle) ? $this->object->pagetitle : $pageTitle;

        if (empty($alias) && $this->context->getOption('automatic_alias', false)) {
            $alias = $this->object->cleanAlias($pageTitle);
            $autoGenerated = true;
        }

        $friendlyUrlsEnabled = $this->context->getOption('friendly_urls', false) && !empty($pageTitle);

        $duplicateContext = $this->context->getOption('global_duplicate_uri_check', false) ? '' : $this->object->context_key;

        $aliasPath = $this->object->getAliasPath($alias, $this->object->toArray());
        $duplicateId = $this->object->isDuplicateAlias($aliasPath, $duplicateContext);

        if ($duplicateId) {
            if ($friendlyUrlsEnabled) {
                return $this->modx->lexicon('duplicate_uri_found', [
                    'id' => $duplicateId,
                    'uri' => $aliasPath,
                ]);
            } elseif ($autoGenerated) {
                $alias = '';
            }
        }

        if (empty($alias) && $friendlyUrlsEnabled) {
            return $this->modx->lexicon('fred.fe.err.alias_ns');
        }

        $this->object->set('alias', $alias);
        return true;
    }

    public function reversePreparedOutput($tv, $value, $resource): string
    {
        if (!empty($value)) {
            $context = !empty($resource) ? $resource->get('context_key') : $this->modx->context->get('key');
            $sourceCache = $tv->getSourceCache($context);
            $classKey = $sourceCache['class_key'];
            if (!empty($sourceCache) && !empty($classKey)) {
                if ($this->modx->loadClass($classKey)) {
                    /** @var modMediaSource $source */
                    $source = $this->modx->newObject($classKey);
                    if ($source) {
                        $source->fromArray($sourceCache, '', true, true);
                        $source->initialize();
                        $properties = $source->getPropertyList();
                        if (!empty($properties['baseUrl'])) {
                            return ltrim($value, rtrim($properties['baseUrl'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);
                        }
                        //S3 Objects
                        if (!empty($properties['url'])) {
                            return ltrim($value, rtrim($properties['url'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);
                        }
                    }
                }
            }
        }

        return $value;
    }
}
