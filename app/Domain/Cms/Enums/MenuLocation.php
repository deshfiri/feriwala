<?php

namespace App\Domain\Cms\Enums;

/**
 * Where a menu is rendered on the public site (§4, §34). One row per
 * location — `cms_menus.location` is unique, so there is exactly one header
 * menu, one footer menu, one legal-links menu, never a choice of several.
 */
enum MenuLocation: string
{
    case Header = 'header';
    case Footer = 'footer';
    case Legal = 'legal';
}
