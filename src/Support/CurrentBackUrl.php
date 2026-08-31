<?php

namespace MadeCurious\RecordPacker\Support;

use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;

/**
 * Captures "the URL actually being viewed right now" as an absolute URL — used to populate the
 * `BackURL` hidden field on Export/Import modal forms at the moment they are built 
 */
class CurrentBackUrl
{
    public static function capture(): string
    {
        $controller = Controller::curr();

        if (!$controller) {
            return '';
        }

        return Director::absoluteURL($controller->getRequest()->getURL(true));
    }
}
