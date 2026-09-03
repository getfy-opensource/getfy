# Gateways de pagamento – estrutura modular

Cada gateway fica em sua própria pasta (ex: `CajuPay/`, `Efi/`) para facilitar manutenção.

## Gateways no core

- `Contracts/GatewayDriver.php` – interface que todo driver implementa.
- `GatewayRegistry.php` – registro de gateways (config + plugins).

## Plugins (recomendado para novos gateways)

**Não altere o core.** Use o exemplo oficial e a API pública:

1. `php artisan plugin:make meu-gateway --type=gateway`
2. Siga `plugins/getfy-example-gateway/README.md`
3. Docs: [`docs/developers/guides/payment-gateway.md`](../../docs/developers/guides/payment-gateway.md)
4. SDK: `App\PluginSdk\Getfy::gateways()->register([...])` + `App\PluginSdk\Contracts\GatewayDriver`

Webhook genérico: `POST /webhooks/gateways/{slug}`.

UI/tokenização no checkout: `frontend.checkout_gateway_slug` + `frontend.exports.checkout` + `tokenizeCard` (ver exemplo).
