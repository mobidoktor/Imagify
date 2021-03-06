<?php

namespace Skmail\Imagify;

use Imagine\Image\ImageInterface;
use Illuminate\Filesystem\Filesystem;
use Imagine\Gd\Imagine;
use Imagine\Image\Box;
use Imagine\Image\Metadata\MetadataBag;
use Imagine\Image\Palette\RGB;
use Imagine\Image\Point;
use Illuminate\Support\Facades\Response;
use Exception;
use Config;

class Image {

    protected $params = [];

    protected $source;

    protected $files;

    protected $imagine;

    protected $image;

    protected $box;

    protected $options = [];

    protected $processed;

    protected $savePath;

    protected $urlResolver;

    public function __construct(Imagine $imagine,Filesystem $files,UrlResolverInterface $urlResolver)
    {
        $this->files = $files;
        $this->imagine = $imagine;
        $this->urlResolver = $urlResolver;
    }

    /**
     * Set parameters
     *
     * @param $params
     * @return Image
     */
    public function setParams( array $params)
    {
        $this->params = array_merge($this->params,$params);
        return $this;
    }

    public function getParam($param,$default = null){
        if(array_key_exists($param,$this->params)){
            return  $this->params[$param];
        }
        return $default;
    }

    public function setParam($name,$value){
        $this->params[$name] = $value;
        return $this;
    }

    /**
     * @param $source
     * @throws Exception
     * @return Image
     */
    public function setSource($source)
    {
        if(!$this->files->exists($source)){
            if(!$this->config('imagify::default',false)){
                throw new Exception("Image not found");
            }else{
                $this->source = $this->config('imagify::default');
            }
        }else{
            $this->source = $source;
        } 
        return $this;
    }

    public function getSource()
    {
        return $this->source;
    }

    /**
     * Render the image and send a response
     */
    public function response()
    {
        $this->process();
        $format = $this->format();
        $contents = $this->image->get($format,$this->getOptions());
        //Create the response
        $mime = $this->getMimeFromFormat($format);
        $response = Response::make($contents, 200);
        $response->header('Content-Type', $mime);
        return $response;
    }

    public function save()
    {
        $this->process();
        $this->createDirectory();
        $this->image->save($this->getSavePath(),$this->getOptions());
        return $this;
    }


    public  function  process()
    {
        if($this->isProcessed()){
            return ;
        }
        $this->setProcessed(true);
        $this->maxExceed();
        $this->minExceed();
        $this->image = $this->imagine->open($this->getSource());
        $this->box = new Box($this->getParam('width'),$this->getParam('height'));
        $method = $this->getParam('method','resize');
        if(!in_array($method,$this->getMethods())){
            throw new Exception('Undefined method '.  $method);
        }
        if(!$this->existsOption('quality')){
            $this->setOption('quality', $this->config('imagify::quality'));
        }
        if($this->format() === 'png') {
            $this->setOption($this->getOption('quality'),round((100 - $this->getOption('quality')) * 9 / 100));
        }
        $this->{$method}();

        if($this->getParam('watermark') !== false){
            $this->watermark();
        }
        return $this;
    }

    public function crop()
    {
        $this->gdCrop();
        return $this;
    }

    public function resize()
    {
        $imageSize = $this->image->getSize();
        $sourceRatio = $imageSize->getWidth() / $imageSize->getHeight();

        $destReatio = $this->getParam('width') / $this->getParam('height');

        if($destReatio !=  $sourceRatio ){

            $destWidth = ceil($this->getParam('width'));
            $destHeight = ceil($this->getParam('width') / $sourceRatio);

            if($destWidth >  $this->getParam('width') ||  $destHeight > $this->getParam('height')){
                $destWidth = ceil($this->getParam('height')  * $sourceRatio);
                $destHeight = ceil($this->getParam('height') );
            }
        }else{
            $destHeight = $this->getParam('height') ;
            $destWidth =  $this->getParam('width');
        }
        $this->image->resize(new Box($destWidth,$destHeight));
        return $this;
    }
    public function getType(){
        $ext = pathinfo($this->source, PATHINFO_EXTENSION);
        switch($ext){
            case 'jpg':
            case 'jpeg':
                return 'jpeg';
            break;
            default :
                return $ext;
        }
    }

    /**
     * Get the format of an image
     *
     * @return ImageInterface
     */
    public function format()
    {
        $format = @exif_imagetype($this->getSource());
        switch($format) {
            case IMAGETYPE_GIF:
                return 'gif';
                break;
            case IMAGETYPE_JPEG:
                return 'jpeg';
                break;
            case IMAGETYPE_PNG:
                return 'png';
                break;
        }
        return null;
    }

    /**
     * Get mime type from image format
     *
     * @return string
     */
    protected function getMimeFromFormat($format)
    {
        switch($format) {
            case 'gif':
                return 'image/gif';
                break;
            case 'jpg':
            case 'jpeg':
                return 'image/jpeg';
                break;
            case 'png':
                return 'image/png';
                break;
        }
        return null;
    }

    public function setOptions($options)
    {
        $this->options  = $options;
    }

    public function getOptions()
    {
        return $this->options;
    }

    public function setOption($name,$value){
        $this->options[$name] = $value;
        return $this;
    }

    public function getOption($name){
        if($this->existsOption($name)){
            return $this->options[$name];
        }
    }

    public function existsOption($name){
        return array_key_exists($name,$this->options);
    }

    protected function getMethods(){
        return [
            'crop',
            'resize',
        ];
    }

    public function maxExceed(){
        if($this->config('imagify::max.width') && $this->getParam('width') > $this->config('imagify::max.width') ){
            throw new Exception("Maximum width is " . $this->config('imagify::max.width') );
        }
        if($this->config('imagify::max.height') && $this->getParam('height') > $this->config('imagify::max.height') ){
            throw new Exception("Maximum width is " . $this->config('imagify::max.height') );
        }
    }
    
    public function minExceed(){
        if($this->config('imagify::min.width') && $this->getParam('width') < $this->config('imagify::min.width') ){
            throw new Exception("The minimum width is " . $this->config('imagify::min.width') );
        }
        if($this->config('imagify::max.height') && $this->getParam('height') < $this->config('imagify::min.height') ){
            throw new Exception("The minimum height is  = " . $this->config('imagify::min.height') );
        }
    }

    public function config($name,$default = null){
        return Config::get($name,$default);
    }

    public function isProcessed(){
        return (bool)$this->processed;
    }

    public function setProcessed($bool = true){
        $this->processed = $bool;
        return $this;
    }

    public function createDirectory()
    {
        $dir = $this->getSavePath();
        $dir = dirname($dir);
        if(!$this->files->exists($dir)){
            $this->files->makeDirectory($dir, 0777, true,true);
        }
    }

    public function getImage(){
        return $this->image;
    }

    public function getSavePath()
    {
        $arr = [
            'method' => $this->getParam('method') ,
            'width'  => $this->getParam('width') ,
            'height' => $this->getParam('height') ,
            'source' =>$this->getSource()
        ];
        if($this->getParam('watermark') !== false){
            $arr = ['watermark' => 'w' ] + $arr;
        }
        return $this->urlResolver->replaceRouteParameters($arr);
    }

    /**
     * Add watermark
     */
    public function watermark(){
        if(!$this->config('imagify::watermark')){
            return;
        }
        $size      = $this->image->getSize();
        $watermark = $this->imagine->open($this->config('imagify::watermark'));
        $wSize     = $watermark->getSize();
        $watermark = clone \App::make('\Skmail\Imagify\Image');
        $watermark->setSource($this->config('imagify::watermark'));
        list($wWidth,$wHeight) = $this->getBestResize($wSize,$size);
        $watermark->setParams([
            'width' => $wWidth,
            'height' => $wHeight,
            'method' => 'resize',
            'watermark' => false,
            'transparent' => true
        ]);
        $watermark->process();
        $watermark = $watermark->getImage();
        $wSize     = $watermark->getSize();
        $bottomRight = new \Imagine\Image\Point($size->getWidth() - $wSize->getWidth() - 5, $size->getHeight() - $wSize->getHeight() - 5);
        $this->image->paste($watermark, $bottomRight);
    }

    public function getBestResize($wSize,$iSize)
    {
        if($wSize->getWidth() < $iSize->getWidth() && $wSize->getHeight() < $iSize->getHeight()){
            return [$wSize->getWidth(),$wSize->getHeight()] ;
        }
        return $this->getBestResize(new Box($wSize->getWidth() / 3.5,$wSize->getHeight() / 3.5),$iSize);
    }

    /**
     * Crop by gb lib
     * @return $this
     */

    protected function gdCrop()
    {
        $srcBox = $this->image->getSize();
        $sourceWidth = $srcBox->getWidth();
        $sourceHeight = $srcBox->getHeight();
        $targetWidth = $this->box->getWidth();
        $targetHeight = $this->box->getHeight();
        $sourceAspectRatio = $sourceWidth / $sourceHeight;
        $desiredAspectRatio = $targetWidth / $targetHeight;
        if ($sourceAspectRatio > $desiredAspectRatio) {
            $thumbHeight = $targetHeight;
            $thumbWidth = ( int ) ($targetHeight * $sourceAspectRatio);
        } else {
            $thumbWidth = $targetWidth;
            $thumbHeight = ( int ) ($targetWidth / $sourceAspectRatio);
        }
        $tempResource = imagecreatetruecolor($thumbWidth, $thumbHeight);
        imagecopyresampled(
            $tempResource,
            $this->image->getGdResource(),
            0, 0,
            0, 0,
            $thumbWidth, $thumbHeight,
            $sourceWidth, $sourceHeight
        );
        $x0 = ($thumbWidth - $targetWidth) / 2;
        $y0 = ($thumbHeight - $targetHeight) / 2;
        $targetResource = imagecreatetruecolor($targetWidth, $targetHeight);
        imagecopy(
            $targetResource,
            $tempResource,
            0, 0,
            $x0, $y0,
            $targetWidth, $targetHeight
        );
        $this->image = new \Imagine\Gd\Image($targetResource,new RGB,new MetadataBag);
        return $this;
    }
} 
