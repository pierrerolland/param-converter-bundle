<?php

namespace RollandRock\ParamConverterBundle\Utils;

use Doctrine\Common\Collections\Collection;

class CollectionUtils
{
    /**
     * @param array|Collection $collection
     *
     * @return bool
     */
    public static function isEmpty($collection)
    {
        if (is_array($collection)) {
            return count($collection) === 0;
        }

        return $collection instanceof Collection && $collection->count() === 0;
    }

    public static function add(&$collection, $value)
    {
        if (is_array($collection)) {
            $collection[] = $value;
        } elseif ($collection instanceof Collection) {
            $collection->add($value);
        }
    }

    public static function remove(&$collection, $id)
    {
        if (is_array($collection)) {
            unset($collection[$id]);
        } elseif ($collection instanceof Collection) {
            $collection->remove($id);
        }
    }
}
