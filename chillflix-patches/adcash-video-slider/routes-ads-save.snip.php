$ads = SiteAds::save([
    'enabled' => !empty($body['enabled']),
    'banner' => !empty($body['banner']),
    'interstitial' => !empty($body['interstitial']),
    'videoSlider' => !empty($body['videoSlider']),
]);
