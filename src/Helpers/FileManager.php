<?php

namespace Tadcms\FileManager\Helpers;

use Spatie\ImageOptimizer\OptimizerChainFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tadcms\FileManager\Repositories\MediaRepository;

class FileManager
{
    /**
     * @var \Illuminate\Support\Facades\Storage $storage
     * */
    protected $storage;
    
    /**
     * @var MediaRepository $mediaRepository
     * */
    protected $mediaRepository;
    
    protected $resource;
    
    protected $resource_type;
    
    protected $folder_id;
    
    protected $type = 'image';
    
    protected $errors = [];
    
    public function __construct(MediaRepository $mediaRepository)
    {
        $this->mediaRepository = $mediaRepository;
        
        $this->storage = Storage::disk(
            config('file-manager.upload_disk')
        );
    }
    
    /**
     * Add resource for upload
     *
     * @param $resource
     * @return $this
     *
     * @throws \Exception
     */
    public function withResource($resource) {
        $this->resource = $resource;
    
        if (is_a($this->resource, 'Illuminate\Http\UploadedFile')) {
            $this->resource_type = 'uploaded';
        }
        
        if (filter_var($this->resource, FILTER_VALIDATE_URL)) {
            $this->resource_type = 'url';
        }
        
        if (is_string($this->resource) && file_exists($this->resource)) {
            $this->resource_type = 'path';
        }
        
        if (empty($this->resource_type)) {
            throw new \Exception('Resource type unsupported.');
        }
    
        return $this;
    }
    
    /**
     * Set media Folder
     *
     * @param int $folder_id
     * @return $this
     * */
    public function setFolder($folder_id) {
        if (empty($folder_id) || $folder_id <= 0) {
            $folder_id = null;
        }
        
        $this->folder_id = $folder_id;
        return $this;
    }
    
    /**
     * Set media Type
     *
     * @param string $type
     * @return $this
     * */
    public function setType($type) {
        $this->type = $type;
        return $this;
    }
    
    /**
     * @return string|\Tadcms\FileManager\Models\Media
     * */
    public function save() {
        $uploadedFile = $this->makeUploadedFile();
        
        if (!$this->fileIsValid($uploadedFile)) {
            unlink($uploadedFile->getRealPath());
            return false;
        }
        
        $filename = $this->makeFilename($uploadedFile);
        $newPath = $this->storage->putFileAs(
            $this->makeFolderUpload(),
            $uploadedFile,
            $filename
        );
    
        if (config('file-manager.image-optimizer')) {
            if (in_array($uploadedFile->getMimeType(), $this->getImageMimetype())) {
                $optimizerChain = OptimizerChainFactory::create();
                $optimizerChain->optimize($this->storage->path($newPath));
            }
        }
        
        $media = $this->mediaRepository->create([
            'name' => $uploadedFile->getClientOriginalName(),
            'type' => $this->type,
            'mimetype' => $uploadedFile->getMimeType(),
            'path' => $newPath,
            'size' => $uploadedFile->getSize(),
            'extension' => $uploadedFile->getClientOriginalExtension(),
            'folder_id' => $this->folder_id,
        ]);
    
        unlink($uploadedFile->getRealPath());
        
        return $media;
    }
    
    public function delete($path)
    {
        if ($this->storage->exists($path)) {
            $this->storage->delete($path);
        }
        return true;
    }
    
    public function url($path)
    {
        if ($this->storage->exists($path)) {
            return $this->storage->url($path);
        }
        return false;
    }
    
    public function isImage($source)
    {
        if (is_a($source, 'Tadcms\FileManager\Models\Media')) {
            return in_array($source->mimetype, $this->getImageMimetype());
        }
        
        return false;
    }
    
    protected function getImageMimetype()
    {
        return [
            'image/jpeg',
            'image/pjpeg',
            'image/png',
            'image/gif',
            'image/svg+xml',
        ];
    }
    
    protected function makeFolderUpload() {
        $folderPath = date('Y/m/d');
    
        // Make Directory if not exists
        if (!$this->storage->exists($folderPath)) {
            File::makeDirectory($this->storage->path($folderPath), 0777, true);
        }
        
        return $folderPath;
    }
    
    /**
     * Make Uploaded File
     * @return \Illuminate\Http\UploadedFile
     * */
    protected function makeUploadedFile() {
        switch ($this->resource_type) {
            case 'path': return $this->makeUploadedFileByPath();
            case 'url': return $this->makeUploadedFileByUrl();
            default: return $this->resource;
        }
    }
    
    /**
     * Make Uploaded File By Path
     * @return \Illuminate\Http\UploadedFile
     * */
    protected function makeUploadedFileByPath() {
        return (new UploadedFile($this->resource, basename($this->resource)));
    }
    
    /**
     * Make Uploaded File By Url
     * @return \Illuminate\Http\UploadedFile
     * */
    protected function makeUploadedFileByUrl() {
        $content = @file_get_contents($this->resource);
        $temp_name = basename($this->resource);
        $this->storage->put($temp_name, $content);
        return (new UploadedFile($this->storage->path($temp_name), $temp_name));
    }
    
    protected function makeFilename(UploadedFile $file) {
        $filename = $file->getClientOriginalName();
        $extension = $file->getClientOriginalExtension();
        
        $filename = str_replace('.' . $extension, '', $filename);
        $filename = Str::slug(substr($filename, 0, 50));
        $filename = $filename . '-'. Str::random(15) .'.' . $extension;
        
        return $this->replaceInsecureSuffix($filename);
    }
    
    protected function replaceInsecureSuffix($name) {
        return preg_replace("/\.php$/i", '', $name);
    }
    
    protected function fileIsValid($file)
    {
        if (empty($file)) {
            array_push($this->errors, trans('error.file-empty'));
            return false;
        }
        
        if (! $file instanceof UploadedFile) {
            array_push($this->errors, trans('error.instance'));
            return false;
        }
        
        if ($file->getError() != UPLOAD_ERR_OK) {
            $msg = 'File failed to upload. Error code: ' . $file->getError();
            array_push($this->errors, $msg);
            return false;
        }
        
        $mimetype = $file->getMimeType();
        
        // Bytes to MB
        $max_size = config('file-manager.file_types.'. $this->type .'.max_file_size');
        $file_size = $file->getSize();
        
        $valid_mimetypes = config('file-manager.file_types.'. $this->type .'.mimetypes', []);
        if (in_array($mimetype, $valid_mimetypes) === false) {
            array_push($this->errors, trans('error.mime') . $mimetype);
            return false;
        }
        
        if ($max_size > 0) {
            if ($file_size > ($max_size * 1024 * 1024)) {
                array_push($this->errors, trans('error.size'));
                return false;
            }
        }
        
        return true;
    }
}