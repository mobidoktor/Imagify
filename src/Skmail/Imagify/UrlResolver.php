<?php
/**
 * @author: Solaiman Kmail - Bluetd <s.kmail@blue.ps> 
 */

namespace Skmail\Imagify;

use Illuminate\Foundation\Application;

class UrlResolver implements UrlResolverInterface
{
    protected $app;

    public function __construct(Application $app)
    {
        $this->app  = $app;
    }

    /**
     * Replace route parameters
     *
     * @param $array
     * @return mixed|string
     */
    public function replaceRouteParameters($array)
    {
        $route = $this->route();

        if(isset($array['watermark'])){
            $array['source'] = 'w/' . $array['source'];
        }
        foreach($array as $name => $value) {
    		if(is_array($value) && array_key_exists('file', $value)){
    			$value = $value['file'];
    		}
            $route = str_replace('{' . $name . '}', $value, $route);
        }
        return $route;
    }

    /**
     * Return full url
     * @param $path
     * @return mixed
     */
    public function url($path)
    {
        return \URL::to($path);
    }

    /**
     * Return full route
     * @return string
     */
    public function route()
    {
        return $this->app['config']->get('imagify::base_route') . '/' . $this->app['config']->get('imagify::route');
    }
} 