<?php

namespace App\Gateways\Pagarme;

/** Erro da API financeira da Pagar.me. A mensagem nunca contém a chave. */
class PagarmeFinanceException extends \RuntimeException {}
