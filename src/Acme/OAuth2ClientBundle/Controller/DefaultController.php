<?php

namespace Acme\OAuth2ClientBundle\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Session\Session;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Guzzle\Http\Client;

class DefaultController extends Controller
{
    protected function initialize()
    {
        $this->oauth2 = array(
            'server' => $this->container->getParameter('oauth2.server'),
            'client_id' => $this->container->getParameter('oauth2.client_id'),
            'client_secret' => $this->container->getParameter('oauth2.client_secret'),
            'endpoints' => array(
                'token' => $this->container->getParameter('oauth2.token_endpoint'),
                'authorization' => $this->container->getParameter('oauth2.authorization_endpoint')
            )
        );
    }

    /**
     * @Route("/")
     */
    public function indexAction()
    {
        $this->initialize();

        $Session = new Session();
        $nonce = $Session->get('nonce');
        $state = $Session->get('state');
        if (empty($nonce) || empty($state)) {
            $nonce = sha1(openssl_random_pseudo_bytes(100));
            $state = sha1(openssl_random_pseudo_bytes(100));
            $Session->set('nonce', $nonce);
            $Session->set('state', $state);
        }
        return $this->render('AcmeOAuth2ClientBundle:Default:index.html.twig',
                             array(
                                 'access_token' => null,
                                 'nonce' => $nonce,
                                 'state' => $state,
                                 'oauth2' => $this->oauth2
                             )
        );
    }

    /**
     * @Route("/api/example")
     */
    public function apiCallAction()
    {
        $this->initialize();

        $Session = new Session();
        $nonce = $Session->get('nonce');
        $state = $Session->get('state');

        $em = $this->getDoctrine()->getManager();
        $params = array();
        $OAuth2 = $em->getRepository('AcmeOAuth2ClientBundle:OAuth2\Client')->find(1);
        if (!is_object($OAuth2)) {
            throw new \Exception('OAuth2 unauthorization');
        }
        $client = new Client($this->oauth2['server']);
        // if ($OAuth2->isExpire()) {
        //     $params = array(
        //         'grant_type'    => 'refresh_token',
        //         'client_id'      => $FreeeLight->getClientId(),
        //         'client_secret'      => $FreeeLight->getClientSecret(),
        //         'refresh_token'         => $OAuth2->getRefreshToken(),
        //     );

        //     $data = $client->post('/oauth/token?grant_type=refresh_token', array(), $params)->send()->json();
        //     $OAuth2->setPropertiesFromArray($data);
        //     $app['orm.em']->flush();
        // } else {
        //     $app['session']->set('access_token', 'not expire');
        // }
        $example = $client->get('/api/v1/products',
                                array(
                                    'Authorization' => 'Bearer '.$OAuth2->getAccessToken()
                                ),
                                $params)->send()->json();
        var_dump($example);
        return $this->render('AcmeOAuth2ClientBundle:Default:index.html.twig',
                             array(
                                 'access_token' => $OAuth2->getAccessToken(),
                                 'nonce' => $nonce,
                                 'state' => $state,
                                 'oauth2' => $this->oauth2
                             )
        );
    }

    /**
     * @Route("/oauth2/receive_authcode")
     */
    public function oAuth2Action()
    {
        $this->initialize();

        $Session = new Session();
        $nonce = $Session->get('nonce');
        $state = $Session->get('state');

        $request = Request::createFromGlobals();
        $authorized_code = $request->get('code');

        if ($request->get('state') != $state) {
            throw new BadRequestHttpException('Illegal state');
        }

        $client = new Client($this->oauth2['server']);
        $params = array(
            'grant_type' => 'authorization_code',
            'code' => $authorized_code,
            'client_id' => $this->oauth2['client_id'],
            'client_secret' => $this->oauth2['client_secret'],
            'state' => $state,
            'redirect_uri' => 'http://localhost:8000/oauth2/receive_authcode'
        );

        $token = $client->post($this->oauth2['endpoints']['token'], array(), $params)->send()->json();

        $id_token = null;
        // validate id_token
        if (array_key_exists('id_token', $token) && !empty($token['id_token'])) {
            $id_token = $token['id_token'];
            $payload = $client->get('/OAuth2/tokeninfo?id_token='.$id_token, array())->send()->json();

            if ($payload['issuer'] != $this->oauth2['server'].'/') {
                throw new BadRequestHttpException('Illegal Issuer');
            }
            if ($payload['audience'] != $this->oauth2['client_id']) {
                throw new BadRequestHttpException('Illegal audience');
            }
            if ($payload['expires_in'] < time()) {
                throw new BadRequestHttpException('Token expires');
            }
            if ($payload['issued_at'] + 300 < time()) {
                throw new BadRequestHttpException('issued expires');
            }
            if ($payload['nonce'] != $nonce) {
                throw new BadRequestHttpException('Illegal nonce');
            }
        }
        $em = $this->getDoctrine()->getManager();
        $OAuth2 = $em->getRepository('AcmeOAuth2ClientBundle:OAuth2\Client')->find(1);
        if (!is_object($OAuth2)) {
            $OAuth2 = new \Acme\OAuth2ClientBundle\Entity\OAuth2\Client();
            $OAuth2->setAccessToken($token['access_token']);
            if (array_key_exists('refresh_token', $token)) {
                $OAuth2->setRefreshToken($token['refresh_token']);
            }
            $OAuth2->setExpiresIn($token['expires_in']);
            $OAuth2->setTokenType($token['token_type']);
            $OAuth2->setScope($token['scope']);
            $OAuth2->setIdToken($id_token);
            $OAuth2->setUpdatedAt(new \DateTime());
            $OAuth2->setNonce($nonce);
            $em->persist($OAuth2);
        } else {
            $OAuth2->setAccessToken($token['access_token']);
            if (array_key_exists('refresh_token', $token)) {
                $OAuth2->setRefreshToken($token['refresh_token']);
            }
            $OAuth2->setExpiresIn($token['expires_in']);
            $OAuth2->setTokenType($token['token_type']);
            $OAuth2->setScope($token['scope']);
            $OAuth2->setIdToken($id_token);
            $OAuth2->setNonce($nonce);
            $OAuth2->setUpdatedAt(new \DateTime());
        }
        $em->flush($OAuth2);

        var_dump($token);
        return $this->redirect('/');
    }

    /**
     * @Route("/oauth2/refresh")
     */
    public function refreshAction()
    {
        $this->initialize();

        $em = $this->getDoctrine()->getManager();
        $OAuth2 = $em->getRepository('AcmeOAuth2ClientBundle:OAuth2\Client')->find(1);
        if (!is_object($OAuth2)) {
            throw new \Exception('OAuth2 unauthorization');
        }
        $client = new Client($this->oauth2['server']);
        var_dump($OAuth2->getRefreshToken());
        $params = array(
                'grant_type'    => 'refresh_token',
                'client_id' => $this->oauth2['client_id'],
                'client_secret' => $this->oauth2['client_secret'],
                'refresh_token'         => $OAuth2->getRefreshToken(),
        );

        $token = $client->post($this->oauth2['server'], array()
                               , $params)->send()->json();
        $OAuth2->setAccessToken($token['access_token']);
        if (array_key_exists('refresh_token', $token)) {
            $OAuth2->setRefreshToken($token['refresh_token']);
        }
        $OAuth2->setExpiresIn($token['expires_in']);
        $OAuth2->setTokenType($token['token_type']);
        $OAuth2->setScope($token['scope']);
        if (array_key_exists('id_token', $token)) {
            $OAuth2->setIdToken($token['id_token']);
        } else {
            $OAuth2->setIdToken(null);
        }
        $OAuth2->setUpdatedAt(new \DateTime());
        $em->flush($OAuth2);
        return $this->redirect('/');
    }

    /**
     * @Route("/oauth2/tokeninfo")
     */
    public function tokenInfoAction()
    {
        $this->initialize();

        $Session = new Session();
        $nonce = $Session->get('nonce');
        $state = $Session->get('state');

        $em = $this->getDoctrine()->getManager();
        $OAuth2 = $em->getRepository('AcmeOAuth2ClientBundle:OAuth2\Client')->find(1);
        if (!is_object($OAuth2)) {
            throw new \Exception('OAuth2 unauthorization');
        }
        $client = new Client($this->oauth2['server']);
        $payload = $client->get('/OAuth2/tokeninfo?id_token='.$OAuth2->getIdToken(),array())->send()->json();
        var_dump($payload);
        return $this->render('AcmeOAuth2ClientBundle:Default:index.html.twig',
                             array(
                                 'access_token' => null,
                                 'nonce' => $nonce,
                                 'state' => $state,
                                 'oauth2' => $this->oauth2
                             )
        );
    }

    /**
     * @Route("/logout")
     */
    public function logoutAction()
    {
        $this->initialize();
        $Session = new Session();
        $Session->remove('nonce');
        $Session->remove('state');
        $em = $this->getDoctrine()->getManager();
        $OAuth2 = $em->getRepository('AcmeOAuth2ClientBundle:OAuth2\Client')->find(1);
        if (is_object($OAuth2)) {
            $OAuth2->setAccessToken(null);
            $OAuth2->setRefreshToken(null);
            $OAuth2->setExpiresIn(null);
            $OAuth2->setTokenType(null);
            $OAuth2->setScope(null);
            $OAuth2->setIdToken(null);
            $em->flush();
        }
        return $this->redirect('/');
    }

}
