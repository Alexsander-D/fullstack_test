import 'bootstrap/dist/css/bootstrap.min.css';
import 'bootstrap';
import '../css/app.css';

type AuthMode = 'login' | 'register';
type NoticeVariant = 'danger' | 'success' | 'warning';
type OperationType = 'deposit' | 'transfer' | 'reversal' | 'legacy_income' | 'legacy_expense';
type FinancialAction = 'deposit' | 'transfer' | 'reverse';

type User = {
    id: number | string;
    name: string;
    email: string;
};

type TokenResponse = {
    access_token: string;
    user: User;
};

type WalletOperation = {
    id: number;
    type: OperationType;
    description: string;
    amount: string;
    delta: string;
    occurred_on: string;
    counterparty: string | null;
    reversed: boolean;
    can_reverse: boolean;
    reverses_operation_id: number | null;
};

type WalletSummary = {
    balance: string;
};

type OperationPayload = Record<string, string>;

type PendingOperationRequest = {
    userId: string;
    action: FinancialAction;
    key: string;
    data: OperationPayload;
};

type ApiErrorPayload = {
    message?: string;
    errors?: Record<string, string[]>;
};

class ApiError extends Error {
    constructor(public status: number, public payload: ApiErrorPayload) {
        super(payload.message ?? 'A solicitação não foi concluída.');
    }
}

const appElement = document.querySelector<HTMLElement>('#app');
if (!appElement) throw new Error('A página não contém o elemento principal da aplicação.');
const app: HTMLElement = appElement;

const tokenKey = 'wallet_access_token';
const pendingOperationStorageKey = 'wallet_pending_operation';
let token = sessionStorage.getItem(tokenKey);
let currentUser: User | null = null;
let authMode: AuthMode = 'login';
let operations: WalletOperation[] = [];
let isSavingOperation = false;

const currencyFormatter = new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL',
});

async function apiRequest<T>(url: string, options: RequestInit = {}): Promise<T> {
    const headers = new Headers(options.headers);

    if (options.body && !headers.has('Content-Type')) {
        headers.set('Content-Type', 'application/json');
    }
    if (token) headers.set('Authorization', `Bearer ${token}`);

    const response = await fetch(url, { ...options, headers });
    const responseText = await response.text();
    let payload: unknown = {};

    try {
        payload = responseText ? JSON.parse(responseText) : {};
    } catch {
        payload = {};
    }

    if (!response.ok) {
        const errorPayload = payload as ApiErrorPayload;

        if (response.status === 401 && token) {
            token = null;
            currentUser = null;
            sessionStorage.removeItem(tokenKey);
            renderAuth('Sua sessão expirou. Entre novamente.', 'warning');
        }

        throw new ApiError(response.status, errorPayload);
    }

    return payload as T;
}

function escapeHtml(value: string): string {
    const entities: Record<string, string> = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;',
    };

    return value.replace(/[&<>"']/g, (character) => entities[character] ?? character);
}

function showNotice(id: string, message: string, variant: NoticeVariant = 'danger'): void {
    const notice = document.getElementById(id);
    if (!(notice instanceof HTMLElement)) return;

    notice.className = `alert alert-${variant} py-2 mb-3`;
    notice.textContent = message;
    notice.hidden = false;
}

function hideNotice(id: string): void {
    const notice = document.getElementById(id);
    if (notice instanceof HTMLElement) notice.hidden = true;
}

function idempotencyKeyFor(action: FinancialAction, data: OperationPayload): string {
    const userId = String(currentUser?.id ?? '');
    const pendingRequest = sessionStorage.getItem(pendingOperationStorageKey);

    if (pendingRequest) {
        try {
            const pending = JSON.parse(pendingRequest) as PendingOperationRequest;
            if (
                pending.userId === userId
                && pending.action === action
                && JSON.stringify(pending.data) === JSON.stringify(data)
            ) {
                return pending.key;
            }
        } catch {
            sessionStorage.removeItem(pendingOperationStorageKey);
        }
    }

    const key = crypto.randomUUID();
    sessionStorage.setItem(pendingOperationStorageKey, JSON.stringify({ userId, action, key, data }));

    return key;
}

function getErrorMessage(error: unknown, context: 'auth' | 'wallet' = 'wallet'): string {
    if (error instanceof ApiError) {
        if (error.status === 401) {
            return context === 'auth'
                ? 'E-mail ou senha inválidos. Verifique os dados e tente novamente.'
                : 'Sua sessão expirou. Entre novamente para continuar.';
        }
        if (error.status === 409 || (error.status === 422 && error.payload.message && error.payload.message !== 'The given data was invalid.')) {
            return error.payload.message ?? 'Não foi possível concluir a operação.';
        }
        if (error.status === 422) {
            if (context === 'wallet') return 'Confira o valor, o destinatário e a descrição.';
            if (error.payload.errors?.email) return 'Confira o e-mail; ele pode ser inválido ou já estar cadastrado.';
            if (error.payload.errors?.password) return 'A senha precisa ter pelo menos 8 caracteres.';
            if (error.payload.errors?.name) return 'Informe seu nome.';
            return 'Confira o e-mail e a senha e tente novamente.';
        }
        return error.payload.message ?? 'Não foi possível concluir a operação.';
    }

    return 'Não foi possível conectar à API. Confira se o servidor Laravel está rodando.';
}

function renderAuth(message = '', variant: NoticeVariant = 'danger'): void {
    app.innerHTML = `
        <section class="auth-shell mx-auto py-4 py-lg-5">
            <header class="d-flex align-items-center gap-3 mb-4">
                <span class="brand-mark" aria-hidden="true">mb</span>
                <div class="fw-bold fs-5">Carteira pessoal</div>
            </header>
            <section class="auth-card">
                <h1 class="h4 fw-bold mb-4">Acesse sua carteira</h1>
                <div class="auth-tabs mb-4" role="tablist" aria-label="Acesso">
                    <button type="button" data-auth-mode="login" class="${authMode === 'login' ? 'active' : ''}" aria-selected="${authMode === 'login'}">Entrar</button>
                    <button type="button" data-auth-mode="register" class="${authMode === 'register' ? 'active' : ''}" aria-selected="${authMode === 'register'}">Criar conta</button>
                </div>
                <div id="auth-feedback" role="alert" hidden></div>
                <form id="auth-form" novalidate>
                    ${authMode === 'register' ? `
                        <div class="mb-3">
                            <label for="auth-name" class="form-label fw-medium">Seu nome</label>
                            <input id="auth-name" name="name" class="form-control" autocomplete="name" maxlength="255" required>
                        </div>
                    ` : ''}
                    <div class="mb-3">
                        <label for="auth-email" class="form-label fw-medium">E-mail</label>
                        <input id="auth-email" name="email" type="email" class="form-control" autocomplete="email" maxlength="255" required>
                    </div>
                    <div class="mb-4">
                        <label for="auth-password" class="form-label fw-medium">Senha</label>
                        <input id="auth-password" name="password" type="password" class="form-control" autocomplete="${authMode === 'login' ? 'current-password' : 'new-password'}" minlength="8" required>
                        ${authMode === 'register' ? '<div class="form-text">Use pelo menos 8 caracteres.</div>' : ''}
                    </div>
                    <button id="auth-submit" type="submit" class="btn btn-success w-100 py-2 fw-semibold">${authMode === 'login' ? 'Entrar na carteira' : 'Criar minha conta'}</button>
                </form>
            </section>
        </section>
    `;

    if (message) showNotice('auth-feedback', message, variant);

    app.querySelectorAll<HTMLButtonElement>('[data-auth-mode]').forEach((button) => {
        button.addEventListener('click', () => {
            authMode = button.dataset.authMode === 'register' ? 'register' : 'login';
            renderAuth();
        });
    });

    const form = app.querySelector<HTMLFormElement>('#auth-form');
    form?.addEventListener('submit', (event) => void submitAuth(event, form));
}

async function submitAuth(event: SubmitEvent, form: HTMLFormElement): Promise<void> {
    event.preventDefault();
    hideNotice('auth-feedback');

    const formData = new FormData(form);
    const body: Record<string, string> = {
        email: String(formData.get('email') ?? ''),
        password: String(formData.get('password') ?? ''),
    };

    if (authMode === 'register') body.name = String(formData.get('name') ?? '');

    const submitButton = app.querySelector<HTMLButtonElement>('#auth-submit');
    if (submitButton) {
        submitButton.disabled = true;
        submitButton.textContent = 'Aguarde...';
    }

    try {
        const endpoint = authMode === 'register' ? '/api/register' : '/api/login';
        const result = await apiRequest<TokenResponse>(endpoint, {
            method: 'POST',
            body: JSON.stringify(body),
        });

        token = result.access_token;
        currentUser = result.user;
        sessionStorage.setItem(tokenKey, token);
        await renderDashboard();
    } catch (error) {
        showNotice('auth-feedback', getErrorMessage(error, 'auth'));
    } finally {
        if (submitButton) {
            submitButton.disabled = false;
            submitButton.textContent = authMode === 'login' ? 'Entrar na carteira' : 'Criar minha conta';
        }
    }
}

async function renderDashboard(): Promise<void> {
    if (!currentUser) {
        renderAuth();
        return;
    }

    app.innerHTML = `
        <div class="app-shell container py-4 py-lg-5">
            <header class="d-flex align-items-center gap-3 mb-4 mb-lg-5">
                <span class="brand-mark" aria-hidden="true">mb</span>
                <div class="fw-bold fs-5">Carteira pessoal</div>
                <div class="ms-auto d-flex align-items-center gap-3">
                    <div class="text-end d-none d-sm-block">
                        <div class="fw-semibold">${escapeHtml(currentUser.name)}</div>
                    </div>
                    <button id="logout-button" type="button" class="btn btn-outline-secondary btn-sm px-3">Sair</button>
                </div>
            </header>

            <div id="wallet-feedback" role="alert" hidden></div>

            <section class="balance-panel p-4 p-md-5 mb-4">
                <div class="balance-label small fw-medium">Saldo disponível</div>
                <div id="balance-value" class="balance-value mt-2">R$ 0,00</div>
            </section>

            <section class="row g-3 mb-4">
                <div class="col-12 col-lg-6">
                    <div class="panel-card h-100">
                        <h2 class="h5 fw-bold mb-4">Depositar</h2>
                        <form id="deposit-form">
                            <div class="mb-3">
                                <label for="deposit-amount" class="form-label fw-medium">Valor (R$)</label>
                                <input id="deposit-amount" name="amount" type="number" class="form-control" min="0.01" max="9999999999.99" step="0.01" placeholder="0,00" required>
                            </div>
                            <div class="mb-4">
                                <label for="deposit-description" class="form-label fw-medium">Descrição (opcional)</label>
                                <input id="deposit-description" name="description" class="form-control" maxlength="255" placeholder="Ex.: depósito em dinheiro">
                            </div>
                            <button type="submit" class="btn btn-success w-100 fw-semibold">Confirmar depósito</button>
                        </form>
                    </div>
                </div>
                <div class="col-12 col-lg-6">
                    <div class="panel-card h-100">
                        <h2 class="h5 fw-bold mb-4">Transferir</h2>
                        <form id="transfer-form">
                            <div class="mb-3">
                                <label for="recipient-email" class="form-label fw-medium">E-mail de quem recebe</label>
                                <input id="recipient-email" name="recipient_email" type="email" class="form-control" autocomplete="email" maxlength="255" required>
                            </div>
                            <div class="mb-3">
                                <label for="transfer-amount" class="form-label fw-medium">Valor (R$)</label>
                                <input id="transfer-amount" name="amount" type="number" class="form-control" min="0.01" max="9999999999.99" step="0.01" placeholder="0,00" required>
                            </div>
                            <div class="mb-4">
                                <label for="transfer-description" class="form-label fw-medium">Descrição (opcional)</label>
                                <input id="transfer-description" name="description" class="form-control" maxlength="255" placeholder="Ex.: pagamento compartilhado">
                            </div>
                            <button type="submit" class="btn btn-success w-100 fw-semibold">Confirmar transferência</button>
                        </form>
                    </div>
                </div>
            </section>

            <section class="panel-card">
                <div class="d-flex justify-content-between align-items-start gap-3 mb-2">
                    <div>
                        <h2 class="h5 fw-bold mb-0">Depósitos e transferências</h2>
                    </div>
                    <span id="operation-count" class="badge rounded-pill text-bg-light">0</span>
                </div>
                <div id="operation-list" class="mt-3" aria-live="polite"></div>
            </section>
        </div>
    `;

    app.querySelector<HTMLButtonElement>('#logout-button')?.addEventListener('click', () => void logout());
    app.querySelector<HTMLFormElement>('#deposit-form')?.addEventListener('submit', (event) => void submitOperation(event, 'deposit'));
    app.querySelector<HTMLFormElement>('#transfer-form')?.addEventListener('submit', (event) => void submitOperation(event, 'transfer'));

    try {
        await loadWallet();
    } catch (error) {
        showNotice('wallet-feedback', getErrorMessage(error));
    }
}

function formatCurrency(value: string | number): string {
    return currencyFormatter.format(Number(value));
}

function formatDate(value: string): string {
    return new Date(`${value.slice(0, 10)}T00:00:00`).toLocaleDateString('pt-BR');
}

async function loadWallet(): Promise<void> {
    const [summary, response] = await Promise.all([
        apiRequest<WalletSummary>('/api/wallet/summary'),
        apiRequest<{ data: WalletOperation[] }>('/api/wallet/operations'),
    ]);

    operations = response.data;
    const balance = app.querySelector<HTMLElement>('#balance-value');
    const count = app.querySelector<HTMLElement>('#operation-count');

    if (balance) balance.textContent = formatCurrency(summary.balance);
    if (count) count.textContent = String(operations.length);

    renderOperations();
}

function renderOperations(): void {
    const list = app.querySelector<HTMLElement>('#operation-list');
    if (!list) return;

    if (operations.length === 0) {
        list.innerHTML = '<div class="text-center py-5"><div class="brand-mark mx-auto mb-3" aria-hidden="true">+</div><div class="fw-semibold">Sua carteira começa aqui</div></div>';
        return;
    }

    list.innerHTML = operations.map((operation) => {
        const delta = Number(operation.delta);
        const label = operation.type === 'deposit' ? 'Depósito'
            : operation.type === 'transfer' ? (delta < 0 ? 'Transferência enviada' : 'Transferência recebida')
                : operation.type === 'reversal' ? 'Reversão'
                    : operation.type === 'legacy_income' ? 'Entrada anterior' : 'Saída anterior';
        const counterparty = operation.counterparty
            ? `<span class="operation-counterparty">${delta < 0 ? 'Para' : 'De'} ${escapeHtml(operation.counterparty)}</span>`
            : '';
        const status = operation.reversed ? '<span class="badge text-bg-light">Revertida</span>' : '';
        const reverseButton = operation.can_reverse
            ? `<button type="button" class="btn btn-sm btn-light" data-operation-action="reverse" data-operation-id="${operation.id}">Reverter</button>`
            : '';

        return `
            <article class="operation-row">
                <div class="min-w-0">
                    <div class="operation-description fw-semibold">${escapeHtml(operation.description)}</div>
                    <div class="small muted d-flex flex-wrap gap-2">
                        <span>${label} · ${formatDate(operation.occurred_on)}</span>
                        ${counterparty}
                        ${status}
                    </div>
                </div>
                <div class="operation-side">
                    <span class="operation-amount ${delta >= 0 ? 'income' : 'expense'}">${formatCurrency(operation.delta)}</span>
                    ${reverseButton}
                </div>
            </article>
        `;
    }).join('');

    list.querySelectorAll<HTMLButtonElement>('[data-operation-action="reverse"]').forEach((button) => {
        button.addEventListener('click', () => void reverseOperation(button));
    });
}

async function submitOperation(event: SubmitEvent, action: 'deposit' | 'transfer'): Promise<void> {
    event.preventDefault();
    if (isSavingOperation) return;

    const form = event.currentTarget;
    if (!(form instanceof HTMLFormElement)) return;

    hideNotice('wallet-feedback');
    const formData = new FormData(form);
    const data: OperationPayload = {
        amount: String(formData.get('amount') ?? ''),
        description: String(formData.get('description') ?? '').trim(),
    };
    if (action === 'transfer') data.recipient_email = String(formData.get('recipient_email') ?? '').trim();

    const button = form.querySelector<HTMLButtonElement>('button[type="submit"]');
    const originalText = button?.textContent ?? '';
    isSavingOperation = true;
    if (button) {
        button.disabled = true;
        button.textContent = 'Processando...';
    }

    try {
        await apiRequest(action === 'deposit' ? '/api/wallet/deposits' : '/api/wallet/transfers', {
            method: 'POST',
            headers: { 'Idempotency-Key': idempotencyKeyFor(action, data) },
            body: JSON.stringify(data),
        });

        sessionStorage.removeItem(pendingOperationStorageKey);
        form.reset();
        showNotice('wallet-feedback', action === 'deposit' ? 'Depósito confirmado.' : 'Transferência realizada.', 'success');
        try {
            await loadWallet();
        } catch {
            showNotice('wallet-feedback', 'Operação confirmada, mas não foi possível atualizar o histórico agora.', 'warning');
        }
    } catch (error) {
        showNotice('wallet-feedback', getErrorMessage(error));
    } finally {
        isSavingOperation = false;
        if (button?.isConnected) {
            button.disabled = false;
            button.textContent = originalText;
        }
    }
}

async function reverseOperation(button: HTMLButtonElement): Promise<void> {
    if (isSavingOperation) return;

    const operationId = Number(button.dataset.operationId);
    const operation = operations.find((item) => item.id === operationId);
    if (!operation || !operation.can_reverse) return;
    if (!window.confirm(`Reverter a operação de ${formatCurrency(operation.amount)}? O lançamento original continuará no histórico.`)) return;

    const answer = window.prompt('Informe o motivo da reversão:');
    if (answer === null) return;
    const reason = answer.trim();
    if (!reason) {
        showNotice('wallet-feedback', 'Informe o motivo para registrar a reversão.');
        return;
    }

    isSavingOperation = true;
    button.disabled = true;
    const data = { operation_id: String(operationId), reason };

    try {
        await apiRequest(`/api/wallet/operations/${operationId}/reverse`, {
            method: 'POST',
            headers: { 'Idempotency-Key': idempotencyKeyFor('reverse', data) },
            body: JSON.stringify({ reason }),
        });

        sessionStorage.removeItem(pendingOperationStorageKey);
        showNotice('wallet-feedback', 'Reversão registrada no histórico.', 'success');
        try {
            await loadWallet();
        } catch {
            showNotice('wallet-feedback', 'Reversão confirmada, mas não foi possível atualizar o histórico agora.', 'warning');
        }
    } catch (error) {
        showNotice('wallet-feedback', getErrorMessage(error));
    } finally {
        isSavingOperation = false;
        if (button.isConnected) button.disabled = false;
    }
}

async function logout(): Promise<void> {
    let message = 'Você saiu da sua carteira.';
    let variant: NoticeVariant = 'success';

    try {
        await apiRequest('/api/logout', { method: 'POST' });
    } catch {
        message = 'A sessão foi removida deste navegador, mas não foi possível confirmar a revogação no servidor.';
        variant = 'warning';
    }

    token = null;
    currentUser = null;
    operations = [];
    sessionStorage.removeItem(tokenKey);
    sessionStorage.removeItem(pendingOperationStorageKey);
    renderAuth(message, variant);
}

async function startApp(): Promise<void> {
    if (!token) {
        renderAuth();
        return;
    }

    try {
        const response = await apiRequest<{ user: User }>('/api/me');
        currentUser = response.user;
        await renderDashboard();
    } catch (error) {
        if (token) renderAuth(getErrorMessage(error), 'warning');
    }
}

void startApp();