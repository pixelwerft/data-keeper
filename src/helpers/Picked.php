<?php

namespace pixelwerft\datakeeper\helpers;

use craft\web\Request;

/**
 * What a list with tick boxes sent: the one row whose own button was clicked, or the rows that were ticked.
 */
final class Picked
{
    /**
     * @param string $name the name of the tick boxes (sent as `$name[]`); the button of a row sends `one`
     * @return string[] anything that is not plain text is dropped
     */
    public static function from(Request $request, string $name): array
    {
        $one = $request->getBodyParam('one');
        if (is_string($one) && $one !== '') {
            return [$one];
        }
        $many = $request->getBodyParam($name, []);
        return array_values(array_filter(is_array($many) ? $many : [], fn($value) => is_string($value) && $value !== ''));
    }
}
