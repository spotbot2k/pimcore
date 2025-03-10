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

namespace Pimcore\Model\Element\Tag;

use Pimcore\Db\Helper;
use Pimcore\Model;
use Pimcore\Model\Element\Tag;

/**
 * @internal
 *
 * @property \Pimcore\Model\Element\Tag $model
 */
class Dao extends Model\Dao\AbstractDao
{
    /**
     * @param int $id
     *
     * @throws Model\Exception\NotFoundException
     */
    public function getById($id)
    {
        $data = $this->db->fetchAssociative('SELECT * FROM tags WHERE id = ?', [$id]);
        if (!$data) {
            throw new Model\Exception\NotFoundException('Tag item with id ' . $id . ' not found');
        }
        $this->assignVariablesToModel($data);
    }

    /**
     * Save object to database
     *
     * @return bool
     *
     * @throws \Exception
     *
     * @todo: not all save methods return a boolean, why this one?
     */
    public function save()
    {
        if (strlen(trim(strip_tags($this->model->getName()))) < 1) {
            throw new \Exception(sprintf('Invalid name for Tag: %s', $this->model->getName()));
        }

        $this->db->beginTransaction();

        try {
            $dataAttributes = $this->model->getObjectVars();

            $originalIdPath = null;
            if ($this->model->getId()) {
                $originalIdPath = $this->db->fetchOne('SELECT idPath FROM tags WHERE id = ?', [$this->model->getId()]);
            }

            $data = [];
            foreach ($dataAttributes as $key => $value) {
                if (in_array($key, $this->getValidTableColumns('tags'))) {
                    $data[$key] = $value;
                }
            }

            Helper::insertOrUpdate($this->db, 'tags', $data);

            $lastInsertId = $this->db->lastInsertId();
            if (!$this->model->getId() && $lastInsertId) {
                $this->model->setId((int) $lastInsertId);
            }

            //check for id-path and update it, if path has changed -> update all other tags that have idPath == idPath/id
            if ($originalIdPath && $originalIdPath != $this->model->getIdPath()) {
                $this->db->query('UPDATE tags SET idPath = REPLACE(idPath, ?, ?)  WHERE idPath LIKE ?;', [$originalIdPath, $this->model->getIdPath(), Helper::escapeLike($originalIdPath) . $this->model->getId() . '/%']);
            }

            $this->db->commit();

            return true;
        } catch (\Exception $e) {
            $this->db->rollBack();

            throw $e;
        }
    }

    /**
     * Deletes object from database
     *
     * @throws \Exception
     */
    public function delete()
    {
        $this->db->beginTransaction();

        try {
            $this->db->delete('tags_assignment', ['tagid' => $this->model->getId()]);
            $this->db->executeStatement('DELETE FROM tags_assignment WHERE ' . Helper::quoteInto($this->db, 'tagid IN (SELECT id FROM tags WHERE idPath LIKE ?)', Helper::escapeLike($this->model->getIdPath()) . $this->model->getId() . '/%'));

            $this->db->delete('tags', ['id' => $this->model->getId()]);
            $this->db->executeStatement('DELETE FROM tags WHERE ' . Helper::quoteInto($this->db, 'idPath LIKE ?', Helper::escapeLike($this->model->getIdPath()) . $this->model->getId() . '/%'));

            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollBack();

            throw $e;
        }
    }

    /**
     * @param string $cType
     * @param int $cId
     *
     * @return Model\Element\Tag[]
     */
    public function getTagsForElement($cType, $cId)
    {
        $tags = [];
        $tagIds = $this->db->fetchFirstColumn('SELECT tagid FROM tags_assignment WHERE cid = ? AND ctype = ?', [$cId, $cType]);

        foreach ($tagIds as $tagId) {
            $tags[] = Model\Element\Tag::getById($tagId);
        }

        $tags = array_filter($tags);
        @usort($tags, function ($left, $right) {
            return strcmp($left->getNamePath(), $right->getNamePath());
        });

        return $tags;
    }

    /**
     * @param string $cType
     * @param int $cId
     */
    public function addTagToElement($cType, $cId)
    {
        $this->doAddTagToElement($this->model->getId(), $cType, $cId);
    }

    /**
     * @param int $tagId
     * @param string $cType
     * @param int $cId
     */
    protected function doAddTagToElement($tagId, $cType, $cId)
    {
        $data = [
            'tagid' => $tagId,
            'ctype' => $cType,
            'cid' => $cId,
        ];
        Helper::insertOrUpdate($this->db, 'tags_assignment', $data);
    }

    /**
     * @param string $cType
     * @param int $cId
     */
    public function removeTagFromElement($cType, $cId)
    {
        $this->db->delete('tags_assignment', [
            'tagid' => $this->model->getId(),
            'ctype' => $cType,
            'cid' => $cId,
        ]);
    }

    /**
     * @param string $cType
     * @param int $cId
     * @param array $tags
     *
     * @throws \Exception
     */
    public function setTagsForElement($cType, $cId, array $tags)
    {
        $this->db->beginTransaction();

        try {
            $this->db->delete('tags_assignment', ['ctype' => $cType, 'cid' => $cId]);

            foreach ($tags as $tag) {
                $this->doAddTagToElement($tag->getId(), $cType, $cId);
            }

            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollBack();

            throw $e;
        }
    }

    /**
     * @param string $cType
     * @param array $cIds
     * @param array $tagIds
     * @param bool $replace
     */
    public function batchAssignTagsToElement($cType, array $cIds, array $tagIds, $replace)
    {
        if ($replace) {
            $quotedCIds = [];
            foreach ($cIds as $cId) {
                $quotedCIds[] = $this->db->quote($cId);
            }
            $this->db->executeStatement('DELETE FROM tags_assignment WHERE ' . 'ctype = ' . $this->db->quote($cType) . ' AND cid IN (' . implode(',', $quotedCIds) . ')');
        }

        foreach ($tagIds as $tagId) {
            foreach ($cIds as $cId) {
                $this->doAddTagToElement($tagId, $cType, $cId);
            }
        }
    }

    /**
     * Retrieves all elements that have a specific tag or one of its child tags assigned
     *
     * @param Tag    $tag               The tag to search for
     * @param string $type              The type of elements to search for: 'document', 'asset' or 'object'
     * @param array  $subtypes          Filter by subtypes, eg. page, object, email, folder etc.
     * @param array  $classNames        For objects only: filter by classnames
     * @param bool   $considerChildTags Look for elements having one of $tag's children assigned
     *
     * @return array
     */
    public function getElementsForTag(
        Tag $tag,
        $type,
        array $subtypes = [],
        array $classNames = [],
        $considerChildTags = false
    ) {
        $elements = [];

        $map = [
            'document' => ['documents', 'id', 'type', '\Pimcore\Model\Document'],
            'asset' => ['assets', 'id', 'type', '\Pimcore\Model\Asset'],
            'object' => ['objects', 'o_id', 'o_type', '\Pimcore\Model\DataObject\AbstractObject'],
        ];

        $select = $this->db->createQueryBuilder()->select(['*'])
                           ->from('tags_assignment')
                           ->andWhere('tags_assignment.ctype = :ctype')->setParameter(':ctype', $type);

        if (true === $considerChildTags) {
            $select->innerJoin('tags_assignment', 'tags', 'tags', 'tags.id = tags_assignment.tagid');
            $select->andWhere(
                '(' .
                Helper::quoteInto($this->db, 'tags_assignment.tagid = ?', $tag->getId()) . ' OR ' .
                Helper::quoteInto($this->db, 'tags.idPath LIKE ?', Helper::escapeLike($tag->getFullIdPath()) . '%')
                . ')'
            );
        } else {
            $select->andWhere('tags_assignment.tagid = :tagId')->setParameter(':tagId', $tag->getId());
        }

        $select->innerJoin('tags_assignment', $map[$type][0], 'el', 'tags_assignment.cId = el.' . $map[$type][1]);

        if (! empty($subtypes)) {
            foreach ($subtypes as $subType) {
                $quotedSubTypes[] = $this->db->quote($subType);
            }
            $select->andWhere($map[$type][2] . ' IN (' . implode(',', $quotedSubTypes) . ')');
        }

        if ('object' === $type && ! empty($classNames)) {
            foreach ($classNames as $cName) {
                $quotedClassNames[] = $this->db->quote($cName);
            }
            $select->andWhere('o_className IN ( ' .  implode(',', $quotedClassNames) . ' )');
        }

        $res = $this->db->query((string) $select, $select->getParameters());

        while ($row = $res->fetch()) {
            $el = $map[$type][3]::getById($row['cid']);
            if ($el) {
                $elements[] = $el;
            }
        }

        return $elements;
    }

    /**
     * @param string $tagPath separated by "/"
     *
     * @return null|Tag
     */
    public function getByPath($tagPath)
    {
        $parentTagId = 0;

        $tag = null;
        $tagPath = ltrim($tagPath, '/');
        foreach (explode('/', $tagPath) as $tagItem) {
            $tags = new Tag\Listing();
            $tags->addConditionParam('name = ?', $tagItem);

            if (empty($parentTagId)) {
                $tags->addConditionParam('parentId = 0 OR parentId IS NULL'); // NULL is allowed by database schema
            } else {
                $tags->addConditionParam('parentId = ?', $parentTagId);
            }

            $tags->setLimit(1);

            $tags = $tags->load();

            if (count($tags) === 0) {
                return null;
            }

            $tag = $tags[0];
            $parentTagId = $tag->getId();
        }

        return $tag;
    }

    /**
     * @return bool
     */
    public function exists()
    {
        if (is_null($this->model->getId())) {
            return false;
        }

        return (bool) $this->db->fetchOne('SELECT COUNT(*) FROM tags WHERE id = ?', [$this->model->getId()]);
    }
}
