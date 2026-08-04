<?php
/**
 * Patch notes for app/routes.php (watch embed playerConfig):
 *
 * 1) Before $playerConfig = [...], add:
 *    $providerRows = [];
 *    try {
 *        $pack = PlayerSources::listProviders();
 *        $providerRows = is_array($pack['providers'] ?? null) ? $pack['providers'] : [];
 *    } catch (Throwable $e) {
 *        $providerRows = [];
 *    }
 *
 * 2) Inside $playerConfig include:
 *    'providersApi' => url('/api/player/providers'),
 *    'providers' => $providerRows,
 *
 * 3) Bump watch page assets to:
 *    asset('css/player.css') . '?v=20260804-progressive3'
 *    asset('js/player.js') . '?v=20260804-progressive3'
 */
