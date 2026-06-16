<script setup>
import { ref, watch, computed } from 'vue';
import { X, Loader2 } from 'lucide-vue-next';

const props = defineProps({
  open: { type: Boolean, default: false },
  provider: { type: Object, default: null },
  form: { type: Object, required: true },
  connectionResult: { type: Object, default: () => ({ status: null, message: '' }) },
  sendResult: { type: Object, default: () => ({ status: null, message: '' }) },
  connectionTesting: { type: Boolean, default: false },
  sendTestSending: { type: Boolean, default: false },
});

const emit = defineEmits(['close', 'test-connection', 'send-test', 'save']);

const testEmail = ref('');

watch(() => props.open, (newVal) => {
  if (!newVal) {
    testEmail.value = '';
  }
});

function handleTestConnection() {
  emit('test-connection');
}

function handleSendTest() {
  if (!testEmail.value) return;
  emit('send-test', testEmail.value);
}

const hasFixedDefaults = computed(() => !!props.provider?.defaults);
const isSendGrid = computed(() => props.provider?.id === 'sendgrid');

const inputClass =
    'block w-full rounded-xl border-2 border-zinc-200 bg-white px-4 py-2.5 text-zinc-900 placeholder-zinc-400 transition focus:border-[var(--color-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)]/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white dark:placeholder-zinc-500';
const selectClass =
    'block w-full rounded-xl border-2 border-zinc-200 bg-white px-4 py-2.5 text-zinc-900 transition focus:border-[var(--color-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)]/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white';
const fixedValueClass =
    'block w-full rounded-xl border border-zinc-200 bg-zinc-50 px-4 py-2.5 text-zinc-600 dark:border-zinc-600 dark:bg-zinc-800/50 dark:text-zinc-400';
</script>

<template>
  <!-- Overlay -->
  <Transition
    enter-active-class="transition-opacity duration-200"
    enter-from-class="opacity-0"
    enter-to-class="opacity-100"
    leave-active-class="transition-opacity duration-200"
    leave-from-class="opacity-100"
    leave-to-class="opacity-0"
  >
    <div
      v-if="open"
      class="fixed inset-0 bg-black/30 z-[100000]"
      @click="$emit('close')"
    />
  </Transition>

  <!-- Sidebar -->
  <Transition
    enter-active-class="transition-transform duration-300"
    enter-from-class="translate-x-full"
    enter-to-class="translate-x-0"
    leave-active-class="transition-transform duration-300"
    leave-from-class="translate-x-0"
    leave-to-class="translate-x-full"
  >
    <div
      v-if="open"
      class="fixed top-0 right-0 h-full w-full sm:w-[480px] bg-white dark:bg-zinc-900 shadow-2xl z-[100001] overflow-y-auto"
    >
      <!-- Header -->
      <div class="sticky top-0 bg-white dark:bg-zinc-900 border-b border-zinc-200 dark:border-zinc-700 px-6 py-4 flex items-center justify-between">
        <div class="flex items-center gap-3">
          <img v-if="provider?.logo" :src="provider.logo" alt="" class="h-8 w-auto rounded-lg object-contain" />
          <div>
            <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">{{ provider?.title || 'Configurar E-mail' }}</h2>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ provider?.description || '' }}</p>
          </div>
        </div>
        <button
          type="button"
          class="rounded-lg p-2 hover:bg-zinc-100 dark:hover:bg-zinc-800 transition-colors"
          @click="$emit('close')"
        >
          <X class="h-5 w-5 text-zinc-500" />
        </button>
      </div>

      <!-- Content -->
      <div class="p-6 space-y-6">
        <!-- SendGrid: API Key + Remetente -->
        <template v-if="isSendGrid">
          <section class="space-y-4">
            <h3 class="text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Configuração SendGrid</h3>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">
              Crie uma API Key em <a href="https://app.sendgrid.com/settings/api_keys" target="_blank" rel="noopener noreferrer" class="text-[var(--color-primary)] underline">SendGrid &gt; Settings &gt; API Keys</a>. Deixe em branco para manter a atual.
            </p>
            <div class="space-y-4">
              <div>
                <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">API Key (SendGrid)</label>
                <input v-model="form.sendgrid_api_key" type="password" autocomplete="new-password" :class="inputClass" placeholder="SG.xxx..." />
              </div>
              <div>
                <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">E-mail do remetente</label>
                <input v-model="form.sendgrid_mail_from_address" type="email" :class="inputClass" placeholder="remetente@seudominio.com" />
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">O remetente deve estar verificado no SendGrid (Single Sender ou Domain Authentication).</p>
              </div>
              <div>
                <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Nome do remetente</label>
                <input v-model="form.sendgrid_mail_from_name" type="text" :class="inputClass" placeholder="Ex: Minha Loja" />
              </div>
            </div>
          </section>
        </template>

        <!-- SMTP Configuration (Hostinger ou SMTP genérico) -->
        <section v-else class="space-y-4">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Configurações SMTP</h3>
          
          <div class="grid gap-4 sm:grid-cols-2">
            <!-- Host, Porta, Criptografia: fixos quando o provedor tem defaults (ex.: Hostinger) -->
            <template v-if="hasFixedDefaults">
              <div>
                <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Host</label>
                <div :class="fixedValueClass">smtp.hostinger.com</div>
              </div>
              <div>
                <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Porta</label>
                <div :class="fixedValueClass">465</div>
              </div>
              <div class="sm:col-span-2">
                <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Criptografia</label>
                <div :class="fixedValueClass">SSL</div>
              </div>
              <div class="sm:col-span-2">
                <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Usuário</label>
                <input v-model="form.hostinger_smtp_username" type="text" :class="inputClass" />
              </div>
              <div class="sm:col-span-2">
                <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Senha (deixe em branco para manter)</label>
                <input v-model="form.hostinger_smtp_password" type="password" autocomplete="new-password" :class="inputClass" />
              </div>
            </template>
            <template v-else>
              <div>
                <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Host</label>
                <input v-model="form.smtp_host" type="text" :class="inputClass" />
              </div>
              <div>
                <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Porta</label>
                <input v-model="form.smtp_port" type="text" :class="inputClass" />
              </div>
              <div class="sm:col-span-2">
                <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Criptografia</label>
                <select v-model="form.smtp_encryption" :class="selectClass">
                  <option value="tls">TLS</option>
                  <option value="ssl">SSL</option>
                </select>
              </div>
              <div class="sm:col-span-2">
                <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Usuário</label>
                <input v-model="form.smtp_username" type="text" :class="inputClass" />
              </div>
              <div class="sm:col-span-2">
                <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Senha (deixe em branco para manter)</label>
                <input v-model="form.smtp_password" type="password" autocomplete="new-password" :class="inputClass" />
              </div>
            </template>
          </div>
        </section>

        <!-- Remetente: Hostinger usa o e-mail do usuário SMTP; SMTP genérico permite e-mail separado -->
        <section v-if="!isSendGrid && hasFixedDefaults" class="space-y-4">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Remetente (nome)</h3>
          <p class="text-sm text-zinc-500 dark:text-zinc-400">O e-mail do remetente é o mesmo do usuário SMTP acima. Defina apenas o nome exibido:</p>
          <div>
            <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Nome do remetente</label>
            <input v-model="form.hostinger_mail_from_name" type="text" :class="inputClass" placeholder="Ex: Minha Loja" />
          </div>
        </section>

        <section v-if="!isSendGrid && !hasFixedDefaults" class="space-y-4">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Remetente</h3>
          <div class="space-y-4">
            <div>
              <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">E-mail do remetente</label>
              <input v-model="form.mail_from_address" type="email" :class="inputClass" placeholder="noreply@seudominio.com" />
              <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Pode ser diferente do usuário SMTP, se o servidor permitir.</p>
            </div>
            <div>
              <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Nome do remetente</label>
              <input v-model="form.mail_from_name" type="text" :class="inputClass" placeholder="Ex: Minha Loja" />
            </div>
          </div>
        </section>

        <!-- Test Connection -->
        <section class="space-y-4 pt-4 border-t border-zinc-200 dark:border-zinc-700">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Testar Configuração</h3>
          
          <button
            type="button"
            class="flex w-full items-center justify-center gap-2 rounded-xl bg-white border-2 border-zinc-200 px-4 py-2.5 text-sm font-medium text-zinc-700 hover:bg-zinc-50 disabled:opacity-70 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700 transition-colors"
            :disabled="connectionTesting"
            @click="handleTestConnection"
          >
            <Loader2 v-if="connectionTesting" class="h-4 w-4 animate-spin shrink-0" />
            {{ connectionTesting ? 'Testando...' : 'Testar conexão' }}
          </button>
          <p v-if="connectionResult.status === 'success'" class="rounded-lg bg-green-50 px-3 py-2 text-sm text-green-700 dark:bg-green-900/30 dark:text-green-400">
            {{ connectionResult.message || 'Conexão estabelecida com sucesso.' }}
          </p>
          <p v-if="connectionResult.status === 'error'" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-400">
            {{ connectionResult.message || 'Erro ao testar conexão.' }}
          </p>

          <div class="space-y-3">
            <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Enviar e-mail de teste</label>
            <input
              v-model="testEmail"
              type="email"
              placeholder="destino@exemplo.com"
              :class="inputClass"
            />
            <button
              type="button"
              class="flex w-full items-center justify-center gap-2 rounded-xl bg-[var(--color-primary)] text-white px-4 py-2.5 text-sm font-medium hover:opacity-90 transition-opacity disabled:opacity-50"
              :disabled="!testEmail || sendTestSending"
              @click="handleSendTest"
            >
              <Loader2 v-if="sendTestSending" class="h-4 w-4 animate-spin shrink-0" />
              {{ sendTestSending ? 'Enviando...' : 'Enviar e-mail de teste' }}
            </button>
            <p v-if="sendResult.status === 'success'" class="rounded-lg bg-green-50 px-3 py-2 text-sm text-green-700 dark:bg-green-900/30 dark:text-green-400">
              {{ sendResult.message || 'E-mail de teste enviado com sucesso.' }}
            </p>
            <p v-if="sendResult.status === 'error'" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-400">
              {{ sendResult.message || 'Erro ao enviar e-mail de teste.' }}
            </p>
          </div>
        </section>

        <!-- Salvar -->
        <section class="pt-4 border-t border-zinc-200 dark:border-zinc-700">
          <button
            type="button"
            class="w-full rounded-xl bg-[var(--color-primary)] text-white px-4 py-3 text-sm font-semibold hover:opacity-90 transition-opacity disabled:opacity-50"
            :disabled="form.processing"
            @click="$emit('save')"
          >
            Salvar configurações
          </button>
        </section>
      </div>
    </div>
  </Transition>
</template>
