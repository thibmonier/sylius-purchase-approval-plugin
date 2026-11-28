<?php

return [
    Tmonier\SyliusOrderHoldPlugin\TmonierSyliusOrderHoldPlugin::class => ['all' => true],
    Tmonier\SyliusAdminOrderCreationPlugin\TmonierSyliusAdminOrderCreationPlugin::class => ['all' => true],
    Tmonier\SyliusCompanyAccountsPlugin\TmonierSyliusCompanyAccountsPlugin::class => ['all' => true],
    Tmonier\SyliusWebhookOutboxPlugin\TmonierSyliusWebhookOutboxPlugin::class => ['all' => true],
    Tmonier\SyliusPurchaseApprovalPlugin\TmonierSyliusPurchaseApprovalPlugin::class => ['all' => true],
    // US-304: the probes of this plugin are loaded because the health plugin is installed in the test application.
    Tmonier\SyliusPlatformHealthPlugin\TmonierSyliusPlatformHealthPlugin::class => ['all' => true],
];
