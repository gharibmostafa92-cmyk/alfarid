<?php

declare(strict_types=1);

namespace App\Enums;

enum BannerPosition: string
{
    case MainSlider = 'main_slider';
    case MiddleBanner = 'middle_banner';
    case Popup = 'popup';
}
