<?php

    include( dirname( __FILE__ ) . '/../../config/config.inc.php' );
    include( dirname( __FILE__ ) . '/../../init.php' );

    $moduleName = basename( dirname( __FILE__ ) );
    /**
     * @var LNCCofidis $module
     */
    $module = Module::getInstanceByName( $moduleName );
    if( $module->active ) {
        if( $module->getCronToken() != Tools::getValue( 'token' )
            || !Module::isInstalled( $moduleName )
        )
            die( 'Bad token' );

        $module->runSync();
    }
