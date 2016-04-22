EC-CUBE3 OAuth2.0 Client for PHP
=================================

```
parameters:
##### app/config/parameters.yml に以下を追加してください
    oauth2.server: <oauth2 server host>
    oauth2.client_id: <oauth2 client id>
    oauth2.client_secret: <oauth2 client secret>
    oauth2.token_endpoint: <oauth2 token endpoint>
    oauth2.authorization_endpoint: <oauth2 authorization endpoint>
```

`redirect_uri` には http://localhost:8000/oauth2/receive_authcode を指定してください
