<?php

/*
 * This file is part of the gsaimeos/gs-saferpay.
 *
 * Copyright (C) 2020 by Gilbertsoft LLC (gilbertsoft.org)
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

class TestHelper
{
    private static $aimeos;
    private static $context = [];

    public static function bootstrap()
    {
        $aimeos = self::getAimeos();

        $includepaths = $aimeos->getIncludePaths();
        $includepaths[] = get_include_path();
        set_include_path(implode(PATH_SEPARATOR, $includepaths));
    }

    public static function getContext($site = 'unittest')
    {
        if (!isset(self::$context[$site])) {
            self::$context[$site] = self::createContext($site);
        }

        return clone self::$context[$site];
    }

    private static function getAimeos()
    {
        if (!isset(self::$aimeos)) {
            require_once '.build/vendor/aimeos/aimeos-core/Bootstrap.php';
            spl_autoload_register('Aimeos\\Bootstrap::autoload');

            $extdir = dirname(dirname(dirname(dirname(__FILE__))));
            self::$aimeos = new \Aimeos\Bootstrap([ $extdir ], false);
        }

        return self::$aimeos;
    }

    private static function createContext($site)
    {
        $ctx = new \Aimeos\MShop\Context\Item\Standard();
        $aimeos = self::getAimeos();

        $paths = $aimeos->getConfigPaths('mysql');
        $paths[] = dirname(__FILE__) . DIRECTORY_SEPARATOR . 'config';

        $conf = new \Aimeos\MW\Config\PHPArray([], $paths);
        $ctx->setConfig($conf);

        $dbm = new \Aimeos\MW\DB\Manager\PDO($conf);
        $ctx->setDatabaseManager($dbm);

        $logger = new \Aimeos\MW\Logger\File($site . '.log', \Aimeos\MW\Logger\Base::DEBUG);
        $ctx->setLogger($logger);

        $i18n = new \Aimeos\MW\Translation\None('de');
        $ctx->setI18n([ 'de' => $i18n ]);

        $session = new \Aimeos\MW\Session\None();
        $ctx->setSession($session);

        $localeManager = \Aimeos\MShop\Locale\Manager\Factory::createManager($ctx);
        $localeItem = $localeManager->bootstrap($site, '', '', false);

        $ctx->setLocale($localeItem);

        $ctx->setEditor('gs-saferpay:lib/custom');

        return $ctx;
    }
}
