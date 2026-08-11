<?php

class FhirReferenceDetector
{
    public static function hasReference($resource): bool
    {
        return self::scan($resource);
    }

    private static function scan($value): bool
    {
        if (!is_array($value)) {
            return false;
        }

        if (isset($value['reference']) && is_string($value['reference'])) {
            return true;
        }

        foreach ($value as $v) {
            if (self::scan($v)) {
                return true;
            }
        }

        return false;
    }
}