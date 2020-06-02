<?php

namespace RollandRock\ParamConverterBundle\Finder;

use RollandRock\ParamConverterBundle\Exception\FieldNotFoundInRequestException;
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

        $content = $request->getContent();
        if ($content) {
            $content = json_decode($content, true);

            if (!array_key_exists($key, $content)) {
                throw new FieldNotFoundInRequestException();
            }

            return $content[$key];
        }

        if (!$request->request->has($key) && !$request->query->has($key) && !$request->files->has($key)) {
            throw new FieldNotFoundInRequestException();
        }

        return null;
    }
}
