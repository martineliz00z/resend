# Relatório — Desafio Resend

## Desafio 1 — Contato com cópia
O formulário solicita nome, e-mail e mensagem, enquanto o destinatário fica fixo no `config.php`. O assunto é montado pelo PHP e o `reply_to` usa o e-mail informado pelo visitante. Depois do envio principal, o sistema tenta enviar uma confirmação automática ao visitante; uma falha nessa confirmação é registrada sem invalidar o primeiro envio.

## Desafio 2 — Registro de envios
Cada tentativa é registrada no arquivo `envios.log`, com data e hora, destinatário, código HTTP e ID ou descrição do erro. O arquivo é acrescentado com `FILE_APPEND` e protegido no `.gitignore` e no `.htaccess`. A chave da API não é gravada no log.

## Desafio 3 — Tratamento de erros
O sistema apresenta mensagens próprias para os códigos 401, 403, 422 e 429, além de uma mensagem genérica para códigos inesperados. A falha de rede do cURL é tratada separadamente, e os detalhes técnicos ficam no log, não na tela do visitante.

## Desafio 4 — E-mail com HTML
Os e-mails usam layout em tabela com cabeçalho, corpo e rodapé, estilos inline e largura máxima de 600 pixels. A mensagem passa por `htmlspecialchars`, e o campo `text` também é enviado como alternativa para leitores sem suporte a HTML.

## Ponto extra — Anexos
Foi implementado upload opcional de PDF, PNG e JPG/JPEG, com limite de 5 MB e validação de extensão e tipo MIME. O conteúdo do arquivo é convertido para base64 e enviado no campo `attachments`.

## Qual erro deu mais trabalho e como descobri a causa?
[Preencher depois dos testes reais. Descreva o erro que ocorreu, a mensagem observada e a alteração que resolveu o problema.]

## Por que o config.php não está neste ZIP?
O arquivo contém a chave privada da API e o endereço de destino. Ele deve permanecer somente na máquina do responsável pelo projeto, pois quem tiver acesso à chave pode realizar envios usando a conta do Resend.
