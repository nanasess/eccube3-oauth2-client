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
    /**
     * @Route("/")
     */
    public function indexAction()
    {
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
                                 'state' => $state
                             )
        );
    }

    /**
     * @Route("/api/example")
     */
    public function apiCallAction()
    {
        $Session = new Session();
        $nonce = $Session->get('nonce');
        $state = $Session->get('state');

        $em = $this->getDoctrine()->getManager();
        $params = array();
        $OAuth2 = $em->getRepository('AcmeOAuth2ClientBundle:OAuth2\Client')->find(1);
        if (!is_object($OAuth2)) {
            throw new \Exception('OAuth2 unauthorization');
        }
        $client = new Client('http://192.168.56.101:8081');
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
        $example = $client->get('/api/example',
                                array(
                                    'Authorization' => 'Bearer '.$OAuth2->getAccessToken()
                                ),
                                $params)->send()->json();
        var_dump($example);
        return $this->render('AcmeOAuth2ClientBundle:Default:index.html.twig',
                             array(
                                 'access_token' => $OAuth2->getAccessToken(),
                                 'nonce' => $nonce,
                                 'state' => $state
                             )
        );
    }

    /**
     * @Route("/oauth2/receive_authcode")
     */
    public function oAuth2Action()
    {
        $Session = new Session();
        $nonce = $Session->get('nonce');
        $state = $Session->get('state');

        $request = Request::createFromGlobals();
        $authorized_code = $request->get('code');

        if ($request->get('state') != $state) {
            throw new BadRequestHttpException('Illegal state');
        }

        $client = new Client('http://192.168.56.101:8081');
        $params = array(
            'grant_type' => 'authorization_code',
            'code' => $authorized_code,
            'client_id' => 'testclient',
            'client_secret' => 'testpass',
            'state' => $state,
            'redirect_uri' => 'http://localhost:8000/oauth2/receive_authcode'
        );
        $token = $client->post('/OAuth2/token', array(), $params)->send()->json();

        $id_token = null;
        // validate id_token
        if (array_key_exists('id_token', $token) && !empty($token['id_token'])) {
            $id_token = $token['id_token'];
            $payload = $client->get('/OAuth2/tokeninfo?id_token='.$id_token, array())->send()->json();

            if ($payload['issuer'] != 'http://192.168.56.101:8081/') {
                throw new BadRequestHttpException('Illegal Issuer');
            }
            if ($payload['audience'] != 'testclient') {
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
        $em = $this->getDoctrine()->getManager();
        $OAuth2 = $em->getRepository('AcmeOAuth2ClientBundle:OAuth2\Client')->find(1);
        if (!is_object($OAuth2)) {
            throw new \Exception('OAuth2 unauthorization');
        }
        $client = new Client('http://192.168.56.101:8081');
        var_dump($OAuth2->getRefreshToken());
        $params = array(
                'grant_type'    => 'refresh_token',
                'client_id' => 'testclient',
                'client_secret' => 'testpass',
                'refresh_token'         => $OAuth2->getRefreshToken(),
        );

        $token = $client->post('/OAuth2/token', array()
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
        $Session = new Session();
        $nonce = $Session->get('nonce');
        $state = $Session->get('state');

        $em = $this->getDoctrine()->getManager();
        $OAuth2 = $em->getRepository('AcmeOAuth2ClientBundle:OAuth2\Client')->find(1);
        if (!is_object($OAuth2)) {
            throw new \Exception('OAuth2 unauthorization');
        }
        $client = new Client('http://192.168.56.101:8081');
        $payload = $client->get('/OAuth2/tokeninfo?id_token='.$OAuth2->getIdToken(),array())->send()->json();
        var_dump($payload);
        return $this->render('AcmeOAuth2ClientBundle:Default:index.html.twig',
                             array(
                                 'access_token' => null,
                                 'nonce' => $nonce,
                                 'state' => $state

                             )
        );
    }

    /**
     * @Route("/logout")
     */
    public function logoutAction()
    {
        $Session = new Session();
        $Session->remove('nonce');
        $Session->remove('state');
        return $this->render('AcmeOAuth2ClientBundle:Default:index.html.twig',
                             array(
                                 'access_token' => null,
                                 'nonce' => null,
                                 'state' => null

                             )
        );
    }

}
