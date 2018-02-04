<?php

namespace RollandRock\ParamConverterBundle\Finder;

use Symfony\Component\HttpFoundation\Request;

class RequestFinder
{
    /**
     * @param string $key
     * @param Request $request
     *
     * @return mixed|null
     */
    public function find($key, Request $request)
    {
        if ($value = $request->get($key)) {
            return $value;
        }

        if (!($content = $request->getContent())) {
            return null;
        }

        $content = json_decode($content, true);

        return array_key_exists($key, $content) ? $content[$key] : null;
    }
}
