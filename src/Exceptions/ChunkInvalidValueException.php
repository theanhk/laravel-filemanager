<?php

namespace Tadcms\FileManager\Exceptions;

/**
 * Class Tadcms\FileManager\Exceptions\ChunkInvalidValueException
 *
 * @package    tadcms/filemanager
 * @author     The Anh Dang <dangtheanh16@gmail.com>
 * @link       https://github.com/tadcms/tadcms
 * @license    MIT
 */
class ChunkInvalidValueException extends \Exception
{
    /**
     * ChunkInvalidValueException constructor.
     *
     * @param string         $message
     * @param int            $code
     * @param \Exception|null $previous
     */
    public function __construct(
        $message = 'The chunk parameters are invalid',
        $code = 500,
        \Exception $previous = null
    )
    {
        parent::__construct($message, $code, $previous);
    }
}
