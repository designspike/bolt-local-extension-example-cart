<?php

namespace Bolt\Extension\DesignSpike\Cart;

use Bolt\Extension\SimpleExtension;
use Bolt\Routing\ControllerCollection;
use Silex\Application;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Cart extension class.
 *
 * @author Will Hall <will@designspike.com>
 */
class CartExtension extends SimpleExtension
{
    /**
     * {@inheritdoc}
     */
    protected function registerTwigPaths()
    {
        return [
            'templates' => ['position' => 'prepend', 'namespace' => 'Cart']
        ];
    }

    /**
     * {@inheritdoc}
     */
    protected function registerTwigFunctions()
    {
        return [
            'cart_contents' => 'cartContentsFunction',
            'cart_count' => 'cartCountFunction',
        ];
    }

    /**
     * {@inheritdoc}
     */
    protected function registerFrontendRoutes(ControllerCollection $collection)
    {
        $collection->post('/cart/add',    [$this, 'callbackCartAdd']);
        $collection->post('/cart/update', [$this, 'callbackCartUpdate']);
        $collection->post('/cart/clear',  [$this, 'callbackCartClear']);
        $collection->post('/cart/send',   [$this, 'callbackCartSend']);
        $collection->post('/cart/save',   [$this, 'callbackCartSave']);
        $collection->get( '/cart/remove', [$this, 'callbackCartRemove']);
        $collection->get( '/cart',        [$this, 'callbackCartDisplay']);
        $collection->get( '/product/list', [$this, 'callbackProductList']);
    }

    /**
     * Gives templates access to the cart contents
     */
    public function cartContentsFunction()
    {
        return $this->getContainer()['session']->get('cart');
    }

    /**
     * Gives you the count of all quantities of things in the cart
     *
     */
    public function cartCountFunction()
    {
        $cart = $this->getContainer()['session']->get('cart');
        if (!is_array($cart)) {
            return 0;
        }

        $sum = 0;
        foreach ($cart as $line_item) {
            $sum += $line_item['qty'];
        }

        return $sum;
    }

    public function callbackCartDisplay(Application $app)
    {
        return $app['twig']->render('@Cart/cart.twig', [
            'checkout' => $app['session']->get('checkout')
        ]);
    }

    public function callbackProductList(Application $app)
    {
        return $app['twig']->render('@Cart/product_list.twig');
    }

    public function callbackCartClear(Application $app)
    {
        $app['session']->remove('cart');
        return new RedirectResponse('/cart');
    }

    /**
     * Add one item with a quantity, to the shopping cart
     *
     * @param Application $app
     * @param Request $request
     */
    public function callbackCartAdd(Application $app, Request $request)
    {
        $cart = $app['session']->get('cart');
        $identifier = $request->request->get('identifier');
        $cart[$identifier] = [
            "id"     => (int) $request->request->get('id'),
            "name"   =>       $request->request->get('name'),
            "number" =>       $request->request->get('number'),
            "unit"   =>       $request->request->get('unit'),
            "qty"    => (int) $request->request->get('qty')
        ];
        if ($cart[$identifier] <= 1) {
            unset($cart[$identifier]);
        }
        $app['session']->set('cart', $cart);

        return new RedirectResponse('/cart');
    }

    /**
     * Update multiple items in the shopping cart
     *
     *
     * @param Application $app
     * @param Request $request
     */
    public function callbackCartUpdate(Application $app, Request $request)
    {
        $cart = $app['session']->get('cart');

        foreach ($request->request->get('identifier') as $key => $identifier) {

            if (!$cart[$identifier]) {
                continue;
            }

            $qty = (int) $request->request->get('qty')[$key];

            if ($qty <= 0) {
                unset($cart[$identifier]);
                continue;
            }

            $cart[$identifier]['qty'] = $qty;
        }

        $app['session']->set('cart', $cart);

        return new RedirectResponse('/cart');
    }

    public function callbackCartRemove(Application $app, Request $request)
    {
        $cart = $app['session']->get('cart');
        $identifier = $request->query->get('identifier');
        if (isset($cart[$identifier])) {
            unset($cart[$identifier]);
        }
        $app['session']->set('cart', $cart);
        return new RedirectResponse('/cart');
    }


    /**
     * Manually save the stuff you entered on the checkout form.
     *
     * @param Application $app
     * @param Request $request
     */
    public function callbackCartSave(Application $app, Request $request)
    {
        $this->helperCartSave($app, $request);
        $app['session']->getFlashBag()->add('success', 'Your cart has been saved.');
        return new RedirectResponse('/cart');
    }

    public function callbackCartSend(Application $app, Request $request)
    {
        $this->helperCartSave($app, $request);

        // validation
        if (!trim($request->request->get('email'))) {
            $app['session']->getFlashBag()->add('danger', 'Email is required.');
        }
        if (!trim($request->request->get('name'))) {
            $app['session']->getFlashBag()->add('danger', 'Name is required.');
        }

        // captcha check
        $captcha_json = file_get_contents('https://www.google.com/recaptcha/api/siteverify'.
            '?secret='.$app['config']->get('general/recaptcha/secret_key').
            '&response='.$request->request->get('g-recaptcha-response').
            '&remoteip='.$request->getClientIp()
        );
        $captcha_result = json_decode($captcha_json, true);
        if ($captcha_result['success']!==true) {
            $app['session']->getFlashBag()->add(
                'danger',
                'Failed security test.'
            );
        }

        // bounce them to the cart if there was any problem
        if ($app['session']->getFlashBag()->has('danger')) {
            return new RedirectResponse('/cart');
        }

        // try sending email
        try {

            // render email
            $body = $app['twig']->render('@Cart/email_order.twig', [
                'cart'     => $app['session']->get('cart'),
                'checkout' => $app['session']->get('checkout'),
            ]);
            $subject = "New order from " . $request->request->get('name');

            // send it
            $message = \Swift_Message::newInstance($subject)
                ->setFrom($app['config']->get('general/mailoptions/senderMail'))
                ->setTo($app['config']->get('general/order_receipt_recipients'))
                ->setBody($body, 'text/html');
            if (filter_var($request->request->get('email'), FILTER_VALIDATE_EMAIL)) {
                $message->setReplyTo(
                    $request->request->get('email'),
                    $request->request->get('name')
                );
            }

            $sent = $app['mailer']->send($message);

            if (!$sent) {
                throw new \Exception("Message wasn't actually sent.");
            }

            $app['session']->getFlashBag()->add(
                'success',
                'Your order was sent.'
            );

            // erase the cart and checkout data
            $app['session']->remove('cart');
            $app['session']->remove('checkout');

            return new RedirectResponse('/thankyou');

        } catch (\Exception $e) {
            $app['session']->getFlashBag()->add(
                'danger',
                'Sorry, but an error occurred. Please contact us to complete your request.'
            );

            $app['logger.system']->info(
                "Cart email couldn't be sent: " . $e->getMessage(),
                ['event' => 'exception']
            );
        }

        return new RedirectResponse('/cart');
    }

    /**
     * Helper that saves the checkout to the session.
     *
     */
    public function helperCartSave(Application $app, Request $request)
    {
        // save all the fields
        $checkout = [
            'email'                    => $request->request->get('email'),
            'name'                     => $request->request->get('name'),
            'company'                  => $request->request->get('company'),
            'phone'                    => $request->request->get('phone'),
            'comments'                 => $request->request->get('comments'),
            'billing_address'          => $request->request->get('billing_address'),
            'billing_city'             => $request->request->get('billing_city'),
            'billing_state'            => $request->request->get('billing_state'),
            'billing_zip'              => $request->request->get('billing_zip'),
            'shipping_same_as_billing' => $request->request->get('shipping_same_as_billing'),
            'shipping_address'         => $request->request->get('shipping_address'),
            'shipping_city'            => $request->request->get('shipping_city'),
            'shipping_state'           => $request->request->get('shipping_state'),
            'shipping_zip'             => $request->request->get('shipping_zip'),
        ];
        $app['session']->set('checkout', $checkout);
    }
}
