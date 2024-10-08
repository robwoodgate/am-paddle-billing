# Paddle Billing Plugin for aMember Pro

Paddle Billing is the evolution of Paddle Classic, and is the default billing API for Paddle accounts created after August 8th, 2023.

If you signed up for Paddle before this date, [you need to opt-in](https://developer.paddle.com/changelog/2023/enable-paddle-billing) to Paddle Billing. After you opt in, you can toggle between Paddle Billing and Paddle Classic, and run the two side by side for as long as you need.

### REQUIREMENTS

This plugin requires [aMember](https://www.cogmentis.com/go/amember) v6.x and PHP 7.2 or higher

### INSTALLATION

1. Simply place this plugin folder and files into the */application/default/plugins/payment* folder of your aMember installation.

2. Enable and configure the plugin in *aMember CP -> Setup/Configuration -> Plugins*

### TROUBLESHOOTING

This plugin writes Paddle responses to the aMember Invoice log (aMember admin > Utilities > Logs > Invoice).

In case of an error, please check there as well as in the aMember Error Log (aMember admin > Utilities > Logs > Errors).

### LICENCE / CREDITS

This plugin is provided under the MIT License.

Copyright 2024 (c) Rob Woodgate, Cogmentis Ltd.

Visit my [aMember plugins](https://www.cogmentis.com/system/cart/) store for more great plugins.

Buy me a coffee: <https://donate.cogmentis.com>
