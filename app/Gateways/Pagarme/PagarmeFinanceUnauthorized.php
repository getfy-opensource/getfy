<?php

namespace App\Gateways\Pagarme;

/** A chave financeira não tem permissão para o recurso (401/403 da Pagar.me). */
class PagarmeFinanceUnauthorized extends PagarmeFinanceException {}
