<?php

namespace FoxSocial\PackageBundlerPlugin;


class NestedArray
{

    /**
     * Merges multiple arrays, recursively, and returns the merged array.
     *
     * This function is similar to PHP's array_merge_recursive() function, but
     * it handles non-array values differently. When merging values that are
     * not both arrays, the latter value replaces the former rather than
     * merging with it.
     *
     * Example:
     *
     * @code
     * $link_options_1 = ['fragment' => 'x', 'attributes' => ['title' => t('X'), 'class' => ['a', 'b']]];
     * $link_options_2 = ['fragment' => 'y', 'attributes' => ['title' => t('Y'), 'class' => ['c', 'd']]];
     *
     * // This results in ['fragment' => ['x', 'y'], 'attributes' =>
     * // ['title' => [t('X'), t('Y')], 'class' => ['a', 'b',
     * // 'c', 'd']]].
     * $incorrect = array_merge_recursive($link_options_1, $link_options_2);
     *
     * // This results in ['fragment' => 'y', 'attributes' =>
     * // ['title' => t('Y'), 'class' => ['a', 'b', 'c', 'd']]].
     * $correct = NestedArray::mergeDeep($link_options_1, $link_options_2);
     * @endcode
     *
     * @param mixed ...$params Arrays to merge.
     *
     * @return array The merged array.
     *
     * @see NestedArray::mergeDeepArray()
     */
    public static function mergeDeep(...$params)
    {
        return self::mergeDeepArray($params);
    }

    /**
     * Merges multiple arrays, recursively, and returns the merged array.
     *
     * This function is equivalent to NestedArray::mergeDeep(), except the
     * input arrays are passed as a single array parameter rather than
     * a variable parameter list.
     *
     * The following are equivalent:
     * - NestedArray::mergeDeep($a, $b);
     * - NestedArray::mergeDeepArray([$a, $b]);
     *
     * The following are also equivalent:
     * - call_user_func_array('NestedArray::mergeDeep', $arrays_to_merge);
     * - NestedArray::mergeDeepArray($arrays_to_merge);
     *
     * @param array $arrays
     *   An arrays of arrays to merge.
     * @param bool  $preserveIntegerKeys
     *   (optional) If given, integer keys will be preserved and merged
     *   instead of appended. Defaults to false.
     *
     * @return array
     *   The merged array.
     *
     * @see NestedArray::mergeDeep()
     */
    public static function mergeDeepArray(
        array $arrays,
        $preserveIntegerKeys = false
    ) {
        $result = [];
        foreach ($arrays as $array) {
            foreach ($array as $key => $value) {
                // Renumber integer keys as array_merge_recursive() does
                // unless $preserveIntegerKeys is set to TRUE. Note that PHP
                // automatically converts array keys that are integer strings
                // (e.g., '1') to integers.
                if (is_int($key) && !$preserveIntegerKeys) {
                    $result[] = $value;
                } elseif (isset($result[$key]) &&
                    is_array($result[$key]) &&
                    is_array($value)
                ) {
                    // Recurse when both values are arrays.
                    $result[$key] = self::mergeDeepArray(
                        [$result[$key], $value],
                        $preserveIntegerKeys
                    );
                } else {
                    // Otherwise, use the latter value, overriding any
                    // previous value.
                    $result[$key] = $value;
                }
            }
        }
        return $result;
    }
}
// vim:sw=4:ts=4:sts=4:et: