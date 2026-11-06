<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## Testar a API no Insomnia

O arquivo [`api.json`](api.json) contém a especificação OpenAPI 3.1 da API e
pode ser importado no Insomnia.

1. Inicie a aplicação (por exemplo, com `ddev start`), configure o banco de
   dados e tenha um usuário cadastrado. A URL base incluída no arquivo é
   `https://kautch-beta-3.ddev.site/api`.
2. No Insomnia, selecione **Import > File**, escolha `api.json`, clique em
   **Scan** e depois em **Import**. Na aba **Spec**, selecione **Generate >
   Requests** para criar as requisições. Selecione o ambiente `OpenAPI env`
   gerado; se sua aplicação estiver em outro endereço, ajuste nele `scheme`,
   `host` e `base_path`.
3. Execute `POST /v1/auth/login` com `Content-Type: application/json`. Substitua
   o corpo gerado pelos dados de um usuário existente:

   ```json
   {
     "email": "user@example.com",
     "password": "your-password",
     "device_name": "Insomnia"
   }
   ```

4. Copie o valor de `data.token` da resposta para a variável `bearerToken` do
   ambiente ativo no Insomnia. As requisições protegidas usam esse valor como
   **Bearer Token**; também é possível configurá-lo na autenticação de cada
   requisição. Use `Accept: application/json`. O token é revogado ao executar
   `POST /v1/auth/logout`; não o salve no repositório.
5. Preencha os parâmetros de caminho e os campos de ID com os UUIDs reais
   retornados pela API; o parâmetro `slug`, quando presente, recebe o slug da
   página. Ajuste os demais valores de exemplo conforme os dados desejados.
   Por exemplo, depois do login, execute `GET /v1/websites` ou crie um site
   com `POST /v1/websites`:

   ```json
   {
     "name": "My website",
     "domain": "example.com"
   }
   ```

Login, recuperação/redefinição de senha, consulta de conteúdo publicado e
resolução de rotas são públicos; as demais operações exigem autenticação e
as permissões apropriadas. As respostas JSON incluem `success` e `message` e,
quando houver dados, `data`. Para upload de assets, use `multipart/form-data`,
adicione o campo de arquivo `file` e informe **um só** de `layout_id` ou
`page_version_id` pertencente ao site. Na criação de uma rota, informe o destino
correspondente ao `destination_type` (`page_id`, `asset_id` ou um destino de
redirecionamento).

Após alterar rotas, validações ou documentação da API, atualize o arquivo
importável com:

```bash
php artisan scramble:export --no-interaction
```

O servidor registrado na especificação é gerado a partir da configuração da
aplicação; em um ambiente DDEV, o comando também pode ser executado com
`ddev exec php artisan scramble:export --no-interaction`.

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
