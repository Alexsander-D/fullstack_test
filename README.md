# Desafio Full Stack PHP — Meu bolso

Aplicação de carteira financeira feita com Laravel, SQL, TypeScript e Bootstrap. Usuários podem depositar, enviar e receber transferências, consultar o saldo e solicitar reversões auditadas. Depósitos são manuais para demonstração: não há integração com bancos ou processadores de pagamento.

## Rodar local no Windows

No primeiro uso, configure as dependências e o banco:

```powershell
cd C:\Users\Alexsander\Documents\Github\admission_test\backend
composer install
npm.cmd install
php artisan key:generate
```

Gere o segredo JWT somente se `JWT_SECRET` ainda estiver vazio no seu `.env`; não execute novamente se já houver um segredo configurado:

```powershell
php artisan jwt:secret
```

Aplique as migrations sem apagar os dados existentes:

```powershell
php artisan migrate
```

Inicie o Laravel em um terminal:

```powershell
php artisan serve
```

Para atualizar TypeScript e CSS automaticamente, abra outro terminal na pasta `backend`:

```powershell
npm.cmd run dev
```

Acesse http://127.0.0.1:8000. Se não iniciar o Vite, `npm.cmd run build` gera os arquivos servidos diretamente pelo Laravel. Não use `migrate:fresh` em uma instalação com dados que deseja manter.

## Funcionalidades e regras

- Cadastro e autenticação JWT, perfil atual e logout; respostas não incluem senha ou token em campos públicos do perfil.
- Depósito manual de valor positivo. O depósito sempre soma ao saldo, inclusive quando ele está negativo.
- Transferências pelo e-mail cadastrado do destinatário. O envio exige saldo suficiente, não aceita transferência para a própria conta e credita o destinatário na mesma transação.
- Histórico privado por usuário. Valores são armazenados e somados em centavos inteiros para evitar arredondamentos de ponto flutuante.
- Reversões exigem motivo e ficam como novas operações com lançamentos inversos; o registro original não é editado nem removido. Um participante da transferência ou o dono do depósito pode solicitar a reversão uma única vez. Se o valor já tiver sido gasto, a reversão pode deixar o saldo negativo.
- Cada depósito, transferência e reversão exige `Idempotency-Key` UUID. Repetir a mesma tentativa não duplica o registro; reutilizar a chave com dados diferentes retorna `409`.
- Contas envolvidas são travadas em ordem de ID e cada operação usa uma transação com até cinco tentativas. No SQLite, a configuração inicia a transação com trava imediata e aguarda até cinco segundos.
- Lançamentos da versão anterior são copiados para o novo histórico contábil durante a migration; a tabela original é mantida.

## Validação

```powershell
php artisan test
npm.cmd run typecheck
npm.cmd run build
vendor\bin\pint --test
```

Docker é um diferencial opcional do enunciado e não é necessário para executar esta versão.
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
