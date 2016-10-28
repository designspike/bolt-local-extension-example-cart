Bolt Local Extension Example - Shopping Cart
======================

This is an example of how to use a local extension to add custom functionality for Bolt. It is not intended as a generalized standalone extension.

Installation
============

  1. Drop this folder into your bolt installation at extensions/local/yourname.
  2. Make sure your config.yml has mailoptions set up. You can use mailtrap.io for testing.
  3. Add something like this to your config.yml or config_local.yml:
       ```
       order_receipt_recipients: [me@example.com]
       ```
  4. Go to http://yoursite.com/product/list and add something to the cart :)
