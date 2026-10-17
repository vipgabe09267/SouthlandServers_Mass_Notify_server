<?php

/**
 * ReadBytes trait
 *
 * PHP version 5
 *
 * @author    Jim Wigginton <terrafrost@php.net>
 * @copyright 2015 Jim Wigginton
 * @license   http://www.opensource.org/licenses/mit-license.html  MIT License
 * @link      http://phpseclib.sourceforge.net
 */

namespace phpseclib3\System\SSH\Common\Traits;

/**
 * ReadBytes trait
 *
 * @author  Jim Wigginton <terrafrost@php.net>
 */
trait ReadBytes
{
    /**
     * Read data
     *
     * @param int $length
     * @throws \RuntimeException on connection errors
     */
    public function readBytes($length)
    {
        if (!is_int($length) || $length < 0 || $length > 16777216) {
            throw new \RuntimeException('Invalid SSH agent message length');
        }
        $result = '';
        while (strlen($result) < $length) {
            $temp = fread($this->fsock, $length - strlen($result));
            if ($temp === false || $temp === '') {
                throw new \RuntimeException("Expected $length bytes; got " . strlen($result));
            }
            $result .= $temp;
        }
        return $result;
    }
}
