<?php

namespace KandySoft\MediaKit\Enums;

/**
 * How a video is placed in a frame of other proportions.
 *
 * A gallery puts every file in one frame — a product photo's — and a video
 * rarely has its proportions. `Cover` fills the frame and cuts the rest, as a
 * picture's rendition is cut, from the chosen area when there is one.
 * `Contain` shows the whole video, with bars where it does not reach: right
 * for a wide clip in a tall frame, where cutting would leave a strip of it.
 *
 * A picture is cut into renditions whatever this says; it is the page that
 * reads it, for a video.
 */
enum MediaFit: string
{
    case Cover = 'cover';
    case Contain = 'contain';
}
