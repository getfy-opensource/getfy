# Checkout – componentes por gateway

Cada gateway tem sua própria pasta (ex: `cajupay/`) com um componente por método de pagamento: `Pix.vue`, `Card.vue`, `Boleto.vue`.

## Como adicionar um novo gateway

1. **Backend:** em `config/gateways.php` e, se precisar, em `app/Gateways/<NomeGateway>/` (driver, etc.).

2. **Frontend – componentes:**
   - Crie a pasta `gateways/<slug>/` (ex: `gateways/cajupay/`).
   - Adicione `Pix.vue`, `Card.vue` e/ou `Boleto.vue` conforme os métodos que o gateway oferece.
   - Cada componente recebe as props: `method` (id, label, gateway_slug, gateway_name), `selected`, `primaryColor`.

3. **Registro:** em `gateways/registry.js`:
   - Importe os componentes do novo gateway.
   - Adicione em `gatewayMethodComponents`: `slug: { pix: ComponentePix, card: ComponenteCard, boleto: ComponenteBoleto }`.

Se não houver componente para um par gateway+método, é usado `DefaultMethodCard.vue`.
