
<?php

ini_set('display_errors', '0');
error_reporting(E_ALL);

;

// ================================================
// 1. CONFIGURAÇÕES
// ================================================

$configFile = __DIR__ . '/config.php';

if (!file_exists($configFile)) {
    http_response_code(500);
    exit('Arquivo config.php não encontrado.');
}

$config = require $configFile;

$apiKey = $config['api_key'] ?? null;
$destino = $config['destino'] ?? null;
$remetente = $config['remetente'] ?? null;

if (empty($apiKey) || empty($destino) || empty($remetente)) {
    http_response_code(500);
    exit('Configuração pendente. Verifique o config.php.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.html');
    exit;
}

// ================================================
// 2. REGISTRAR ENVIOS NO LOG
// ================================================

function registrarEnvio(
    string $destinatario,
    int $status,
    string $resultado
): void {

    $arquivo = __DIR__ . '/envios.log';

    $destinatario = str_replace(
        ["\r", "\n", '|'],
        ' ',
        $destinatario
    );

    $resultado = str_replace(
        ["\r", "\n", '|'],
        ' ',
        $resultado
    );

    $linha = date('Y-m-d H:i:s') . ' | '
        . $destinatario . ' | '
        . $status . ' | '
        . $resultado . PHP_EOL;

    if (@file_put_contents(
        $arquivo,
        $linha,
        FILE_APPEND | LOCK_EX
    ) === false) {
        error_log('Não foi possível gravar envios.log.');
    }
}

// ================================================
// 3. COMUNICAÇÃO COM A RESEND
// ================================================

function chamarResend(string $apiKey, array $dados): array
{
    $curl = curl_init('https://api.resend.com/emails');

    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(
            $dados,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json'
        ],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10
    ]);

    $resposta = curl_exec($curl);
    $erroCurl = curl_error($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);

    curl_close($curl);

    if ($resposta === false) {
        return [
            'status' => 0,
            'json' => null,
            'erro' => $erroCurl ?: 'Falha de conexão.'
        ];
    }

    $json = json_decode($resposta, true);

    return [
        'status' => $status,
        'json' => is_array($json) ? $json : [],
        'erro' => null
    ];
}

// ================================================
// 4. EXIBIR RESULTADO
// ================================================

function mostrarResultado(
    string $titulo,
    string $mensagem,
    ?int $codigo = null
): void {

    $tituloSeguro = htmlspecialchars(
        $titulo,
        ENT_QUOTES,
        'UTF-8'
    );

    $mensagemSegura = htmlspecialchars(
        $mensagem,
        ENT_QUOTES,
        'UTF-8'
    );

    $codigoHtml = '';

    if ($codigo !== null) {
        $codigoHtml = '<h2>Erro HTTP: ' . $codigo . '</h2>';
    }

    echo '<!DOCTYPE html>
    <html lang="pt-BR">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>' . $tituloSeguro . '</title>
        <link rel="stylesheet" href="style.css">
    </head>
    <body>
        <main class="container">
            <h1>' . $tituloSeguro . '</h1>
            ' . $codigoHtml . '
            <p>' . $mensagemSegura . '</p>
            <a href="index.html">Voltar ao formulário</a>
        </main>
    </body>
    </html>';

    exit;
}

// ================================================
// 5. RECEBER E VALIDAR FORMULÁRIO
// ================================================

$nome = trim($_POST['nome'] ?? '');
$email = trim($_POST['email'] ?? '');
$mensagem = trim($_POST['mensagem'] ?? '');

if ($nome === '' || $email === '' || $mensagem === '') {
    mostrarResultado(
        'Campos obrigatórios',
        'Preencha todos os campos do formulário.'
    );
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    mostrarResultado(
        'E-mail inválido',
        'Digite um endereço de e-mail válido.'
    );
}

// ================================================
// 6. VALIDAR ANEXOS
// ================================================

$anexos = [];

if (
    isset($_FILES['anexo']) &&
    $_FILES['anexo']['error'] !== UPLOAD_ERR_NO_FILE
) {

    $arquivo = $_FILES['anexo'];

    if ($arquivo['error'] !== UPLOAD_ERR_OK) {
        mostrarResultado(
            'Erro no anexo',
            'Não foi possível receber o arquivo.'
        );
    }

    if ($arquivo['size'] > 5 * 1024 * 1024) {
        mostrarResultado(
            'Arquivo muito grande',
            'O arquivo deve ter no máximo 5 MB.'
        );
    }

    $extensao = strtolower(
        pathinfo($arquivo['name'], PATHINFO_EXTENSION)
    );

    $tiposPermitidos = [
        'pdf' => 'application/pdf',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg'
    ];

    if (!isset($tiposPermitidos[$extensao])) {
        mostrarResultado(
            'Formato inválido',
            'São permitidos apenas PDF, PNG e JPG.'
        );
    }

    $mimeReal = mime_content_type($arquivo['tmp_name']);

    if ($mimeReal !== $tiposPermitidos[$extensao]) {
        mostrarResultado(
            'Arquivo inválido',
            'O conteúdo do arquivo não corresponde ao formato informado.'
        );
    }

    $conteudo = file_get_contents($arquivo['tmp_name']);

    if ($conteudo === false) {
        mostrarResultado(
            'Erro no anexo',
            'Não foi possível ler o arquivo enviado.'
        );
    }

    $anexos[] = [
        'filename' => basename($arquivo['name']),
        'content' => base64_encode($conteudo)
    ];
}

// ================================================
// 7. MONTAR E-MAIL PRINCIPAL
// ================================================

$nomeSeguro = htmlspecialchars($nome, ENT_QUOTES, 'UTF-8');
$emailSeguro = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');

$mensagemSegura = nl2br(
    htmlspecialchars($mensagem, ENT_QUOTES, 'UTF-8')
);

$html = '
<table role="presentation" width="100%" cellpadding="0" cellspacing="0"
style="background-color:#f3f4f6;padding:30px 10px;font-family:Arial,sans-serif;">
<tr>
<td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0"
style="max-width:600px;background-color:#ffffff;border-radius:10px;overflow:hidden;">
<tr>
<td style="background-color:#14213d;color:#ffffff;padding:25px;">
<h1 style="margin:0;font-size:24px;">Novo contato pelo site</h1>
</td>
</tr>
<tr>
<td style="padding:25px;color:#222222;font-size:15px;line-height:1.6;">
<p><strong>Nome:</strong> ' . $nomeSeguro . '</p>
<p><strong>E-mail:</strong> ' . $emailSeguro . '</p>
<p><strong>Mensagem:</strong></p>
<p>' . $mensagemSegura . '</p>
</td>
</tr>
<tr>
<td style="padding:15px 25px;background-color:#eeeeee;color:#666666;font-size:12px;">
Mensagem enviada pelo formulário de contato do site.
</td>
</tr>
</table>
</td>
</tr>
</table>';

$dadosPrincipais = [
    'from' => $remetente,
    'to' => [$destino],
    'subject' => 'Contato do site — ' . $nome,
    'reply_to' => $email,
    'text' => "Nome: $nome\nE-mail: $email\n\nMensagem:\n$mensagem",
    'html' => $html
];

if (!empty($anexos)) {
    $dadosPrincipais['attachments'] = $anexos;
}

// ================================================
// 8. ENVIAR E-MAIL PRINCIPAL
// ================================================

$resultadoPrincipal = chamarResend(
    $apiKey,
    $dadosPrincipais
);

$statusPrincipal = $resultadoPrincipal['status'];
$jsonPrincipal = $resultadoPrincipal['json'] ?? [];

// Falha de conexão

if ($statusPrincipal === 0) {

    registrarEnvio(
        $destino,
        0,
        'Erro de conexão: ' . ($resultadoPrincipal['erro'] ?? 'Desconhecido')
    );

    mostrarResultado(
        'Falha de conexão',
        'Não foi possível conectar ao serviço de envio. Verifique sua internet e tente novamente.'
    );
}

// ================================================
// 9. TRATAR ERROS HTTP
// ================================================

if ($statusPrincipal < 200 || $statusPrincipal >= 300) {

    $erroApi = $jsonPrincipal['message']
        ?? $jsonPrincipal['error']
        ?? 'Erro retornado pela API Resend.';

    registrarEnvio(
        $destino,
        $statusPrincipal,
        (string) $erroApi
    );

    switch ($statusPrincipal) {

        case 401:
            $tituloErro = 'Erro 401 - Não autorizado';
            $mensagemErro = 'A chave de API é inválida ou não foi autorizada. Verifique sua chave de acesso.';
            break;

        case 403:
            $tituloErro = 'Erro 403 - Acesso proibido';
            $mensagemErro = 'O serviço recusou o envio. Verifique se o destinatário está autorizado no modo de teste da Resend.';
            break;

        case 422:
            $tituloErro = 'Erro 422 - Dados inválidos';
            $mensagemErro = 'Os dados enviados não foram aceitos pela API. Verifique os campos obrigatórios da requisição.';
            break;

        case 429:
            $tituloErro = 'Erro 429 - Muitas requisições';
            $mensagemErro = 'O limite de envios foi atingido. Aguarde alguns instantes antes de tentar novamente.';
            break;

        default:
            $tituloErro = 'Erro HTTP ' . $statusPrincipal;
            $mensagemErro = 'Ocorreu um erro inesperado ao enviar sua mensagem. Tente novamente mais tarde.';
            break;
    }

    mostrarResultado(
        $tituloErro,
        $mensagemErro,
        $statusPrincipal
    );
}

// ================================================
// 10. REGISTRAR SUCESSO DO ENVIO PRINCIPAL
// ================================================

$idPrincipal = (string) (
    $jsonPrincipal['id'] ?? 'ID não retornado'
);

registrarEnvio(
    $destino,
    $statusPrincipal,
    $idPrincipal
);

// ================================================
// 11. MONTAR E-MAIL DE CONFIRMAÇÃO
// ================================================

$htmlConfirmacao = '
<table role="presentation" width="100%" cellpadding="0" cellspacing="0"
style="background-color:#f3f4f6;padding:30px 10px;font-family:Arial,sans-serif;">
<tr>
<td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0"
style="max-width:600px;background-color:#ffffff;border-radius:10px;">
<tr>
<td style="background-color:#14213d;color:#ffffff;padding:25px;">
<h1 style="margin:0;font-size:24px;">Mensagem recebida!</h1>
</td>
</tr>
<tr>
<td style="padding:25px;color:#222222;font-size:15px;line-height:1.6;">
<p>Olá, ' . $nomeSeguro . '!</p>
<p>Recebemos sua mensagem e agradecemos pelo contato.</p>
<p>Em breve, entraremos em contato.</p>
</td>
</tr>
</table>
</td>
</tr>
</table>';

$dadosConfirmacao = [
    'from' => $remetente,
    'to' => [$email],
    'subject' => 'Recebemos sua mensagem!',
    'text' => "Olá, $nome!\n\nRecebemos sua mensagem e agradecemos pelo contato. Em breve, entraremos em contato.",
    'html' => $htmlConfirmacao
];

// ================================================
// 12. ENVIAR E REGISTRAR CONFIRMAÇÃO
// ================================================

$resultadoConfirmacao = chamarResend(
    $apiKey,
    $dadosConfirmacao
);

$statusConfirmacao = $resultadoConfirmacao['status'];
$jsonConfirmacao = $resultadoConfirmacao['json'] ?? [];

if (
    $statusConfirmacao >= 200 &&
    $statusConfirmacao < 300
) {

    $idConfirmacao = (string) (
        $jsonConfirmacao['id'] ?? 'ID não retornado'
    );

    registrarEnvio(
        $email,
        $statusConfirmacao,
        'Confirmação: ' . $idConfirmacao
    );

} else {

    $erroConfirmacao = $resultadoConfirmacao['erro']
        ?? $jsonConfirmacao['message']
        ?? $jsonConfirmacao['error']
        ?? 'Falha no envio da confirmação.';

    registrarEnvio(
        $email,
        $statusConfirmacao,
        'Falha na confirmação: ' . (string) $erroConfirmacao
    );

    // A falha da confirmação não cancela o envio principal.
}

// ================================================
// 13. MOSTRAR SUCESSO
// ================================================

mostrarResultado(
    'Mensagem enviada!',
    'Sua mensagem foi enviada com sucesso. Obrigado pelo contato!'
);
