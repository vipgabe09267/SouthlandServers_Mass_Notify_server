<?php

/**
 * Random Number Generator
 *
 * PHP version 5
 *
 * Here's a short example of how to use this library:
 * <code>
 * <?php
 *    include 'vendor/autoload.php';
 *
 *    echo bin2hex(\phpseclib3\Crypt\Random::string(8));
 * ?>
 * </code>
 *
 * @author    Jim Wigginton <terrafrost@php.net>
 * @copyright 2007 Jim Wigginton
 * @license   http://www.opensource.org/licenses/mit-license.html  MIT License
 * @link      http://phpseclib.sourceforge.net
 */

namespace phpseclib3\Crypt;

/**
 * Pure-PHP Random Number Generator
 *
 * @author  Jim Wigginton <terrafrost@php.net>
 */
abstract class Random
{
    /**
     * Generate a random string.
     *
     * @param int $length
     * @throws \RuntimeException if the operating system cannot provide secure randomness
     * @return string
     */
    public static function string($length)
    {
        if (!is_int($length) || $length < 0 || $length > 16777216) {
            throw new \InvalidArgumentException('Random byte length must be an integer from 0 through 16777216');
        }
        if ($length === 0) {
            return '';
        }

        try {
            return random_bytes($length);
        } catch (\Throwable $error) {
            throw new \RuntimeException('A secure random source is unavailable', 0, $error);
        }
    }
}
