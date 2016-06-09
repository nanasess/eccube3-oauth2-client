EC-CUBE3 OAuth2.0 Client for PHP
=================================

### 設定方法
```
parameters:
##### app/config/parameters.yml に以下を追加してください
    oauth2.server: <oauth2 server host: https://example.com>
    oauth2.client_id: <oauth2 client id>
    oauth2.client_secret: <oauth2 client secret>
    oauth2.token_endpoint: <oauth2 token endpoint: /OAuth2/v0/token>
    oauth2.authorization_endpoint_admin: <oauth2 authorization endpoint /admin/OAuth2/v0/authorize>
    oauth2.authorization_endpoint_mypage: <oauth2 authorization endpoint /mypage/OAuth2/v0/authorize>
    oauth2.tokeninfo_endpoint: <tokeninfo endipoint: /OAuth2/v0/tokeninfo>
    oauth2.userinfo_endpoint: <openid connect userinfo endpoint: /OAuth2/v0/userinfo>
```

`redirect_uri` には <http://127.0.0.1:8000/oauth2/receive_authcode> を指定してください
`client_id` 及び `client_secret` は admin/mypage で異なるものを使用する必要がありますのでご注意ください.

[composer](https://getcomposer.org) をインストールしたのち、以下のコマンドを実行してください.

```
php composer.phar install
php app/console doctrine:schema:create
```

### 起動方法

```
php app/console server:start
```

<http://127.0.0.1:8000> にアクセスしてアプリケーションを使用します
