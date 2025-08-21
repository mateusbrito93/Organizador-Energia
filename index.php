<?php
session_start();

function limpar($v)
{
    // Remove aspas e outros caracteres indesejados
    $v = trim($v, " \t\n\r\0\x0B\"'");
    // Remove aspas internas também
    $v = str_replace(['"', "'"], '', $v);
    return $v;
}

// Função para converter qualquer formato de data para o formato padrão dd/mm/yyyy HH:MM:SS
function normalizarData($dataStr)
{
    $dataStr = limpar($dataStr);

    // Se já está no formato dd/mm/yyyy, retorna como está
    if (preg_match('/^\d{2}\/\d{2}\/\d{4}/', $dataStr)) {
        return $dataStr;
    }

    // Se está no formato yyyy-mm-dd, converte para dd/mm/yyyy
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $dataStr, $matches)) {
        $hora = substr($dataStr, 11); // Pega a parte da hora se existir
        return $matches[3] . '/' . $matches[2] . '/' . $matches[1] . (strlen($hora) > 0 ? ' ' . $hora : '');
    }

    // Se está no formato yyyy/mm/dd, converte para dd/mm/yyyy
    if (preg_match('/^(\d{4})\/(\d{2})\/(\d{2})/', $dataStr, $matches)) {
        $hora = substr($dataStr, 11); // Pega a parte da hora se existir
        return $matches[3] . '/' . $matches[2] . '/' . $matches[1] . (strlen($hora) > 0 ? ' ' . $hora : '');
    }

    // Se está no formato dd-mm-yyyy, converte para dd/mm/yyyy
    if (preg_match('/^(\d{2})-(\d{2})-(\d{4})/', $dataStr, $matches)) {
        $hora = substr($dataStr, 11); // Pega a parte da hora se existir
        return $matches[1] . '/' . $matches[2] . '/' . $matches[3] . (strlen($hora) > 0 ? ' ' . $hora : '');
    }

    // Se não reconhece o formato, retorna limpo
    return $dataStr;
}

// Converte para timestamp aceitando qualquer formato de data
function to_ts($dataStr)
{
    $s = normalizarData($dataStr);
    // Remove escaping de barras se existir
    $s = str_replace('\/', '/', $s);

    // Se vem só data, adiciona 00:00:00
    if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $s))
        $s .= ' 00:00:00';

    // dd/mm/yyyy ...
    if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})(.*)$/', $s, $m)) {
        $d = $m[1];
        $mo = $m[2];
        $y = $m[3];
        $rest = trim($m[4]);
        if ($rest === '')
            $rest = '00:00:00';
        return strtotime("$y-$mo-$d $rest");
    }

    return strtotime($s);
}

// Função para normalizar dados para comparação de duplicidade
function normalizarParaComparacao($valor)
{
    $valor = limpar($valor);
    $valor = mb_strtolower($valor, 'UTF-8');
    return $valor;
}

$linhas_raw = isset($_POST['log']) ? trim($_POST['log']) : '';
$data_inicio = isset($_POST['data_inicio']) ? $_POST['data_inicio'] : '';
$data_fim = isset($_POST['data_fim']) ? $_POST['data_fim'] : '';
$sel_players = isset($_POST['players']) && is_array($_POST['players']) ? $_POST['players'] : [];

$rows = [];        // linhas brutas já normalizadas
$players = [];     // lista única para filtro
$resultado = [];   // agregados (após filtros)

// JSON Dados
// Caminho do arquivo de armazenamento
$arquivoDados = "dados.json";

// Carregar dados já existentes
if (file_exists($arquivoDados)) {
    $dadosSalvos = json_decode(file_get_contents($arquivoDados), true);
} else {
    $dadosSalvos = [];
}

// Inicializar dados brutos existentes
$arquivoDadosBrutos = "dados_brutos.json";
$dadosBrutosExistentes = [];

if (file_exists($arquivoDadosBrutos)) {
    $dadosBrutosExistentes = json_decode(file_get_contents($arquivoDadosBrutos), true);
}

// Verificar se é um redirecionamento após processamento
$processamentoConcluido = isset($_SESSION['processamento_concluido']) ? $_SESSION['processamento_concluido'] : false;
$logsDuplicados = isset($_SESSION['logs_duplicados']) ? $_SESSION['logs_duplicados'] : [];
$logsProcessados = isset($_SESSION['logs_processados']) ? $_SESSION['logs_processados'] : 0;

// Processar apenas se for um novo envio, não um redirecionamento
if ($linhas_raw !== '' && isset($_POST['processar']) && !$processamentoConcluido) {
    $linhas = explode("\n", $linhas_raw);
    $logsDuplicados = [];
    $logsProcessados = 0;

    foreach ($linhas as $linha) {
        $linha = trim($linha);
        if ($linha === '')
            continue;

        // Detecta separador (csv vírgula, csv ;, tabs ou múltiplos espaços)
        if (strpos($linha, ",") !== false)
            $colunas = str_getcsv($linha, ",");
        elseif (strpos($linha, ";") !== false)
            $colunas = str_getcsv($linha, ";");
        else
            $colunas = preg_split("/\t+|\s{2,}/", $linha);

        if (count($colunas) < 4)
            continue;
        if (stripos($colunas[0], "data") !== false)
            continue;

        $dataStr = normalizarData(limpar($colunas[0])); // NORMALIZAR A DATA
        $jogador = limpar($colunas[1]);
        $motivo = mb_strtolower(limpar($colunas[2]), 'UTF-8');
        $quantidade = (int) limpar($colunas[3]);

        $ts = to_ts($dataStr);
        if ($ts === false)
            continue;

        // Verificar duplicidade com dados normalizados
        $duplicado = false;
        if (!empty($dadosBrutosExistentes)) {
            foreach ($dadosBrutosExistentes as $registroExistente) {
                // Normalizar todos os campos para comparação
                $dataStrNormalizada = normalizarParaComparacao($dataStr);
                $jogadorNormalizado = normalizarParaComparacao($jogador);
                $motivoNormalizado = normalizarParaComparacao($motivo);

                $dataExistenteNormalizada = normalizarParaComparacao($registroExistente['data_str']);
                $jogadorExistenteNormalizado = normalizarParaComparacao($registroExistente['jogador']);
                $motivoExistenteNormalizado = normalizarParaComparacao($registroExistente['motivo']);

                if (
                    $dataStrNormalizada === $dataExistenteNormalizada &&
                    $jogadorNormalizado === $jogadorExistenteNormalizado &&
                    $motivoNormalizado === $motivoExistenteNormalizado &&
                    $quantidade == $registroExistente['quantidade']
                ) {
                    $duplicado = true;
                    $logsDuplicados[] = "$dataStr - $jogador - $motivo - $quantidade";
                    break;
                }
            }
        }

        if ($duplicado) {
            continue; // Pula registros duplicados
        }

        // Criar array temporário para este registro
        $novoRegistro = [
            'ts' => $ts,
            'data_str' => $dataStr, // Já normalizada para dd/mm/yyyy
            'jogador' => $jogador,
            'motivo' => $motivo,
            'quantidade' => $quantidade
        ];

        $rows[] = $novoRegistro;
        $players[$jogador] = true;
        $logsProcessados++;

        // Processar para dados salvos
        if (!isset($dadosSalvos[$jogador])) {
            $dadosSalvos[$jogador] = [
                "deposito" => 0,
                "saque" => 0,
                "total" => 0,
                "primeiro_negativo" => null
            ];
        }

        if ($motivo === "depósito" || $motivo === "deposito") {
            $dadosSalvos[$jogador]["deposito"] += $quantidade;
            $dadosSalvos[$jogador]["total"] += $quantidade;
        } elseif ($motivo === "saque") {
            $dadosSalvos[$jogador]["saque"] += abs($quantidade);
            $dadosSalvos[$jogador]["total"] += $quantidade;
        }

        // Adicionar aos dados brutos existentes para verificação de duplicidade nos próximos registros
        $dadosBrutosExistentes[] = $novoRegistro;
    }

    // Recalcula o primeiro negativo para todos os jogadores com base no histórico completo
    foreach ($dadosSalvos as $jogador => &$dadosJogador) {
        // Buscar TODAS as transações deste jogador em ordem cronológica
        $transacoesJogador = array_filter($dadosBrutosExistentes, function ($registro) use ($jogador) {
            return $registro['jogador'] === $jogador;
        });

        // Ordenar por timestamp (mais antigas primeiro)
        usort($transacoesJogador, function ($a, $b) {
            return $a['ts'] - $b['ts'];
        });

        $saldoAcumulado = 0;
        $primeiroNegativoData = null;
        $estaNegativo = false;

        foreach ($transacoesJogador as $transacao) {
            if ($transacao['motivo'] === "depósito" || $transacao['motivo'] === "deposito") {
                $saldoAcumulado += $transacao['quantidade'];
            } elseif ($transacao['motivo'] === "saque") {
                $saldoAcumulado += $transacao['quantidade']; // quantidade já é negativa para saques
            }

            // Verificar se ficou negativo pela primeira vez
            if ($saldoAcumulado < 0 && $primeiroNegativoData === null) {
                $primeiroNegativoData = $transacao['data_str'];
                $estaNegativo = true;
            }

            // Se voltou a ficar positivo, resetar o primeiro negativo
            if ($saldoAcumulado >= 0 && $estaNegativo) {
                $primeiroNegativoData = null;
                $estaNegativo = false;
            }

            // Se ficou negativo novamente após ter ficado positivo
            if ($saldoAcumulado < 0 && $primeiroNegativoData === null && !$estaNegativo) {
                $primeiroNegativoData = $transacao['data_str'];
                $estaNegativo = true;
            }
        }

        // Atualizar a data do primeiro negativo
        $dadosJogador["primeiro_negativo"] = $primeiroNegativoData;
    }
    unset($dadosJogador); // Remover a referência

    // Salvar dados no arquivo JSON
    file_put_contents($arquivoDados, json_encode($dadosSalvos, JSON_PRETTY_PRINT));

    // Salvar dados brutos para filtros futuros (apenas os não duplicados)
    file_put_contents($arquivoDadosBrutos, json_encode($dadosBrutosExistentes, JSON_PRETTY_PRINT));

    // Armazenar a data/hora da última atualização REAL
    $ultimaAtualizacao = time();
    file_put_contents("ultima_atualizacao.txt", $ultimaAtualizacao);

    // Armazenar mensagens na sessão para redirecionamento
    $_SESSION['processamento_concluido'] = true;
    $_SESSION['logs_duplicados'] = $logsDuplicados;
    $_SESSION['logs_processados'] = $logsProcessados;

    // Redirecionar para evitar reenvio do formulário
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// --- aplica filtros --- (apenas se não for redirecionamento)
if (!$processamentoConcluido && $linhas_raw !== '') {
    $ts_ini = $data_inicio ? strtotime($data_inicio . " 00:00:00") : null;
    $ts_fim = $data_fim ? strtotime($data_fim . " 23:59:59") : null;
    $usa_players = count($sel_players) > 0;

    foreach ($rows as $r) {
        if ($ts_ini && $r['ts'] < $ts_ini)
            continue;
        if ($ts_fim && $r['ts'] > $ts_fim)
            continue;
        if ($usa_players && !in_array($r['jogador'], $sel_players, true))
            continue;

        $j = $r['jogador'];
        if (!isset($resultado[$j]))
            $resultado[$j] = ['deposito' => 0, 'saque' => 0, 'total' => 0];

        if ($r['motivo'] === 'depósito' || $r['motivo'] === 'deposito') {
            $resultado[$j]['deposito'] += $r['quantidade'];
            $resultado[$j]['total'] += $r['quantidade'];
        } elseif ($r['motivo'] === 'saque') {
            $resultado[$j]['saque'] += abs($r['quantidade']); // Converte negativo para positivo
            $resultado[$j]['total'] += $r['quantidade']; // Já que $r['quantidade'] é negativo, soma negativo = subtrai
        }
    }
}

// Select Múltiplo
$players_list = array_keys($players);
sort($players_list);

// Processar filtros de data para o histórico
$filtrarHistorico = isset($_POST['aplicar_filtro_historico']);
$data_inicio_historico = isset($_POST['data_inicio_historico']) ? $_POST['data_inicio_historico'] : '';
$data_fim_historico = isset($_POST['data_fim_historico']) ? $_POST['data_fim_historico'] : '';
$filtro_jogador_historico = isset($_POST['filtro_jogador_historico']) ? trim($_POST['filtro_jogador_historico']) : '';

// Reprocessar os dados originais
$dadosFiltrados = $dadosSalvos;
$totalGeralDepositos = 0;
$totalGeralSaques = 0;
$totalGeralTotal = 0;

if ($filtrarHistorico && ($data_inicio_historico || $data_fim_historico || $filtro_jogador_historico)) {
    // Dados brutos para filtrar por data
    $arquivoDadosBrutos = "dados_brutos.json";
    $dadosBrutos = [];

    if (file_exists($arquivoDadosBrutos)) {
        $dadosBrutos = json_decode(file_get_contents($arquivoDadosBrutos), true);
    }

    // Reiniciar dados filtrados
    $dadosFiltrados = [];
    $ts_ini_historico = $data_inicio_historico ? strtotime($data_inicio_historico . " 00:00:00") : null;
    $ts_fim_historico = $data_fim_historico ? strtotime($data_fim_historico . " 23:59:59") : null;

    foreach ($dadosBrutos as $registro) {
        if (isset($registro['ts'])) {
            $ts_registro = $registro['ts'];

            // Aplicar filtro de data
            if ($ts_ini_historico && $ts_registro < $ts_ini_historico)
                continue;
            if ($ts_fim_historico && $ts_registro > $ts_fim_historico)
                continue;

            $jogador = $registro['jogador'];

            // Aplicar filtro por jogador (se especificado)
            if ($filtro_jogador_historico && stripos($jogador, $filtro_jogador_historico) === false)
                continue;

            $motivo = $registro['motivo'];
            $quantidade = $registro['quantidade'];

            if (!isset($dadosFiltrados[$jogador])) {
                $dadosFiltrados[$jogador] = [
                    "deposito" => 0,
                    "saque" => 0,
                    "total" => 0
                ];
            }

            if ($motivo === "depósito" || $motivo === "deposito") {
                $dadosFiltrados[$jogador]["deposito"] += $quantidade;
                $dadosFiltrados[$jogador]["total"] += $quantidade;
            } elseif ($motivo === "saque") {
                $dadosFiltrados[$jogador]["saque"] += abs($quantidade);
                $dadosFiltrados[$jogador]["total"] += $quantidade;
            }
        }
    }
}

// ORDENAR OS DADOS FILTRADOS POR NOME DO JOGADOR (ALFABÉTICO)
if (!empty($dadosFiltrados)) {
    ksort($dadosFiltrados); // Ordena pelo índice (nome do jogador) em ordem alfabética
}

// Obter data e hora da última atualização REAL
$arquivoUltimaAtualizacao = "ultima_atualizacao.txt";
if (file_exists($arquivoUltimaAtualizacao)) {
    $timestampUltimaAtualizacao = file_get_contents($arquivoUltimaAtualizacao);
    
    // Usando DateTime para melhor controle de timezone
    $datetime = new DateTime("@$timestampUltimaAtualizacao");
    
    // Horário Brasileiro
    $datetime->setTimezone(new DateTimeZone('America/Sao_Paulo'));
    $dataHoraBR = $datetime->format('d/m/Y H:i:s');
    
    // Horário UTC
    $datetime->setTimezone(new DateTimeZone('UTC'));
    $dataHoraUTC = $datetime->format('d/m/Y H:i:s');
    
} else {
    $dataHoraBR = "Nunca atualizado";
    $dataHoraUTC = "Nunca atualizado";
}

// Verificar se é um redirecionamento após processamento
$processamentoConcluido = isset($_SESSION['processamento_concluido']) ? $_SESSION['processamento_concluido'] : false;

// Limpar a sessão após uso (apenas se foi um redirecionamento)
if ($processamentoConcluido) {
    unset($_SESSION['processamento_concluido']);
    unset($_SESSION['logs_duplicados']);
    unset($_SESSION['logs_processados']);
}
?>
<!DOCTYPE html>
<html lang="pt-br">

<head>
    <meta charset="UTF-8">
    <title>Organizador de Energias</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --bg: #121212;
            --panel: #1e1e1e;
            --muted: #333;
            --text: #e0e0e0;
            --brand: #0078d7;
        }

        * {
            box-sizing: border-box
        }

        body {
            font-family: Segoe UI, system-ui, -apple-system, Arial;
            background: var(--bg);
            color: var(--text);
            margin: 0;
            padding: 24px
        }

        h2,
        h3 {
            margin: 0 0 16px
        }

        .grid {
            display: grid;
            gap: 16px
        }

        .panel {
            background: var(--panel);
            border: 1px solid var(--muted);
            border-radius: 12px;
            padding: 16px
        }

        textarea {
            width: 100%;
            min-height: 160px;
            background: var(--panel);
            color: #fff;
            border: 1px solid var(--muted);
            border-radius: 10px;
            padding: 10px
        }

        label {
            display: block;
            font-size: .95rem;
            margin: 6px 0
        }

        input,
        select {
            background: var(--panel);
            color: #fff;
            border: 1px solid var(--muted);
            border-radius: 8px;
            padding: 8px
        }

        select[multiple] {
            min-height: 120px
        }

        .actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 10px
        }

        button {
            padding: 10px 16px;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            background: linear-gradient(135deg, #0078d7, #00bcd4);
            color: #fff
        }

        button:hover {
            opacity: .9
        }

        table {
            width: 100%;
            border-collapse: collapse;
            overflow: hidden;
            border-radius: 12px;
            margin-top: 10px
        }

        th,
        td {
            border: 1px solid var(--muted);
            padding: 10px;
            text-align: center
        }

        th {
            background: var(--brand);
            color: #fff
        }

        .charts {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px
        }

        @media (max-width: 900px) {
            .charts {
                grid-template-columns: 1fr
            }
        }

        .toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 10px 0;
            padding: 10px;
            background: #1e1e1e;
            border-radius: 8px;
            gap: 20px;
        }

        .toolbar label {
            color: #ccc;
            font-size: 14px;
        }

        .toolbar select,
        .toolbar input {
            background: #121212;
            color: #fff;
            border: 1px solid #333;
            border-radius: 6px;
            padding: 5px 8px;
        }

        .tabs {
            display: flex;
            margin-bottom: 16px;
            border-bottom: 1px solid var(--muted);
        }

        .tab {
            padding: 10px 20px;
            cursor: pointer;
            background: var(--panel);
            border: 1px solid var(--muted);
            border-bottom: none;
            border-radius: 8px 8px 0 0;
            margin-right: 4px;
        }

        .tab.active {
            background: var(--brand);
            color: white;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .total-negativo {
            background-color: #ff4444 !important;
            color: white !important;
            font-weight: bold;
        }

        .total-negativo td {
            background-color: #ff4444 !important;
            color: white !important;
            font-weight: bold;
        }

        #tab-historico .alert-negativo {
            background: #ff4444;
            color: white;
            padding: 10px;
            border-radius: 8px;
            margin-top: 15px;
            border-left: 4px solid #cc0000;
        }

        .alert-sucesso {
            background: #4caf50;
            color: white;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #2e7d32;
        }

        .alert-duplicado {
            background: #ff9800;
            color: white;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #f57c00;
        }

        .alert-duplicado ul,
        .alert-sucesso ul {
            margin: 10px 0;
            padding-left: 20px;
            max-height: 200px;
            overflow-y: auto;
        }

        .alert-duplicado li,
        .alert-sucesso li {
            margin: 5px 0;
            font-family: monospace;
            background: rgba(255, 255, 255, 0.1);
            padding: 5px;
            border-radius: 4px;
        }
    </style>
</head>

<body>
    <h2>📊 Organizador de Energia</h2>

    <!-- Mostrar alerta de duplicidades se houver (após redirecionamento) -->
    <?php if ($processamentoConcluido && !empty($logsDuplicados)): ?>
        <div class="alert-duplicado">
            <strong>⚠️ LOGS DUPLICADOS ENCONTRADOS:</strong>
            <p>Foram encontrados <?= count($logsDuplicados) ?> registros duplicados que não foram processados:</p>
            <ul>
                <?php foreach ($logsDuplicados as $logDuplicado): ?>
                    <li><?= htmlspecialchars($logDuplicado) ?></li>
                <?php endforeach; ?>
            </ul>
            <p><strong><?= $logsProcessados ?> registros novos foram processados com sucesso.</strong></p>
        </div>
    <?php elseif ($processamentoConcluido): ?>
        <div class="alert-sucesso">
            <strong>✅ PROCESSAMENTO CONCLUÍDO!</strong>
            <p><?= $logsProcessados ?> registros foram processados com sucesso.</p>
        </div>
    <?php endif; ?>

    <form method="post" class="grid">
        <div class="panel">
            <label for="log">Cole aqui o LOG (CSV, ponto-e-vírgula, tabulação ou múltiplos espaços):</label>
            <textarea id="log" name="log"><?= $processamentoConcluido ? '' : htmlspecialchars($linhas_raw) ?></textarea>
            <div class="actions">
                <button type="submit" name="processar" value="1">Processar Dados</button>
                <?php if (!empty($resultado)): ?>
                    <!--<button type="submit" name="exportar" value="1">⬇ Exportar Excel</button>-->
                <?php endif; ?>
            </div>
        </div>
    </form><br>

    <!-- Abas para alternar entre dados processados e histórico completo -->
    <div class="tabs">
        <!--<div class="tab <?= !empty($resultado) ? 'active' : '' ?>" onclick="showTab('resultado')">Resultado do
            Processamento</div>-->
        <div class="tab <?= empty($resultado) ? 'active' : '' ?>" onclick="showTab('historico')">Histórico
        </div>
    </div>

    <!-- Conteúdo da aba de Resultado -->
    <div id="tab-resultado" class="tab-content <?= !empty($resultado) ? 'active' : '' ?>">
        <?php if (!empty($resultado)): ?>
            <div class="panel">
                <h3>Resultado do Processamento (com filtros aplicados)</h3>

                <div class="toolbar">
                    <label>🔽 Ordenar por:
                        <select id="ordenarSelect" onchange="ordenarTabela('resultadoTabela')">
                            <option value="0">Jogador</option>
                            <option value="1">Depósitos</option>
                            <option value="2">Saques</option>
                            <option value="3">Total</option>
                        </select>
                    </label>

                    <label>🔍 Buscar Jogador:
                        <input type="text" id="filtroJogador" onkeyup="filtrarTabela('resultadoTabela')"
                            placeholder="Digite o nome...">
                    </label>
                </div>

                <table id="resultadoTabela">
                    <thead>
                        <tr>
                            <th>Jogador</th>
                            <th>Depósitos</th>
                            <th>Saques</th>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($resultado as $jogador => $dados): ?>
                            <tr>
                                <td><?= htmlspecialchars($jogador) ?></td>
                                <td><?= $dados['deposito'] ?></td>
                                <td><?= $dados['saque'] ?></td>
                                <td><?= $dados['total'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="charts">
                <div class="panel"><canvas id="barChart"></canvas></div>
                <div class="panel"><canvas id="pieChart"></canvas></div>
            </div>

            <script>
                const labels = <?= json_encode(array_keys($resultado)) ?>;
                const deposito = <?= json_encode(array_column($resultado, 'deposito')) ?>;
                const saque = <?= json_encode(array_column($resultado, 'saque')) ?>;
                const totais = <?= json_encode(array_column($resultado, 'total')) ?>;

                new Chart(document.getElementById('barChart'), {
                    type: 'bar',
                    data: {
                        labels,
                        datasets: [
                            { label: 'Depósitos', data: deposito, backgroundColor: '#4caf50' },
                            { label: 'Saques', data: saque, backgroundColor: '#f44336' }
                        ]
                    },
                    options: {
                        responsive: true,
                        plugins: { legend: { labels: { color: "#fff" } } },
                        scales: {
                            x: { ticks: { color: "#fff" } },
                            y: { ticks: { color: "#fff" } }
                        }
                    }
                });

                new Chart(document.getElementById('pieChart'), {
                    type: 'pie',
                    data: {
                        labels,
                        datasets: [{ data: totais, backgroundColor: ['#0078d7', '#00bcd4', '#4caf50', '#f44336', '#ff9800', '#9c27b0', '#03a9f4', '#8bc34a', '#e91e63', '#795548'] }]
                    },
                    options: { responsive: true, plugins: { legend: { labels: { color: "#fff" } } } }
                });
            </script>
        <?php else: ?>
            <div class="panel">
                <h3>Nenhum dado processado ainda</h3>
                <p>Cole um LOG acima e clique em "Processar Dados" para ver os resultados.</p>
            </div>
        <?php endif; ?>
    </div>

    <!-- Conteúdo da aba de Histórico -->
    <div id="tab-historico" class="tab-content <?= empty($resultado) ? 'active' : '' ?>">
        <form method="post">
            <div class="panel">
                <h3>Histórico Completo (todos os dados salvos)</h3>

                <!-- Aviso de última atualização -->
                <div
                    style="background: #2c2c2c; padding: 10px; border-radius: 8px; margin-bottom: 15px; border-left: 4px solid #0078d7;">
                    <strong>🕐 Última atualização:</strong><br>
                    • Horário Brasileiro: <?= $dataHoraBR ?><br>
                    • Horário Albion Online: <?= $dataHoraUTC ?>
                </div>

                <!-- Filtros para o histórico -->
                <div class="panel grid"
                    style="grid-template-columns: repeat(auto-fit, minmax(220px,1fr)); align-items:end; margin-bottom: 20px;">
                    <div>
                        <label>Data inicial</label>
                        <input type="date" name="data_inicio_historico"
                            value="<?= htmlspecialchars($data_inicio_historico) ?>">
                    </div>
                    <div>
                        <label>Data final</label>
                        <input type="date" name="data_fim_historico"
                            value="<?= htmlspecialchars($data_fim_historico) ?>">
                    </div>
                    <div>
                        <label>Filtrar por Jogador</label>
                        <input type="text" name="filtro_jogador_historico"
                            value="<?= htmlspecialchars($filtro_jogador_historico) ?>"
                            placeholder="Digite parte do nome..." style="width: 100%;">
                    </div>
                    <div class="actions">
                        <button type="submit" name="aplicar_filtro_historico" value="1">Aplicar Filtro</button>
                        <?php if ($filtrarHistorico): ?>
                            <button type="button" onclick="limparFiltroHistorico()">Limpar Filtro</button>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($filtrarHistorico): ?>
                    <div style="background: #2c2c2c; padding: 10px; border-radius: 8px; margin-bottom: 15px;">
                        <strong>Filtro aplicado:</strong>
                        <?php if ($data_inicio_historico): ?>De:
                            <?= htmlspecialchars($data_inicio_historico) ?>     <?php endif; ?>
                        <?php if ($data_fim_historico): ?> Até:
                            <?= htmlspecialchars($data_fim_historico) ?>     <?php endif; ?>
                        <?php if ($filtro_jogador_historico): ?> | Jogador:
                            <?= htmlspecialchars($filtro_jogador_historico) ?>     <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="toolbar">
                    <label>🔽 Ordenar por:
                        <select id="ordenarSelectHistorico" onchange="ordenarTabela('historicoTabela')">
                            <option value="0">Jogador</option>
                            <option value="1">Depósitos</option>
                            <option value="2">Saques</option>
                            <option value="3">Total</option>
                        </select>
                    </label>

                    <label>🔍 Buscar Jogador:
                        <input type="text" id="filtroJogadorHistorico" onkeyup="filtrarTabela('historicoTabela')"
                            placeholder="Digite o nome...">
                    </label>
                </div>

                <table id="historicoTabela">
                    <thead>
                        <tr>
                            <th>Jogador</th>
                            <th>Depósitos</th>
                            <th>Saques</th>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $totalGeralDepositos = 0;
                        $totalGeralSaques = 0;
                        $totalGeralTotal = 0;
                        $jogadoresNegativos = [];

                        foreach ($dadosFiltrados as $jogador => $dados):
                            $totalGeralDepositos += $dados['deposito'];
                            $totalGeralSaques += $dados['saque'];
                            $totalGeralTotal += $dados['total'];

                            // Verificar se o total é negativo
                            $totalNegativo = $dados['total'] < 0;
                            if ($totalNegativo) {
                                $jogadoresNegativos[] = $jogador;
                            }
                            ?>
                            <tr class="<?= $totalNegativo ? 'total-negativo' : '' ?>">
                                <td><?= htmlspecialchars($jogador) ?></td>
                                <td><?= $dados['deposito'] ?></td>
                                <td><?= $dados['saque'] ?></td>
                                <td><?= $dados['total'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="background-color: #2c2c2c; font-weight: bold;">
                            <td>TOTAL GERAL</td>
                            <td><?= $totalGeralDepositos ?></td>
                            <td><?= $totalGeralSaques ?></td>
                            <td><?= $totalGeralTotal ?></td>
                        </tr>
                    </tfoot>
                </table>

                <?php if (!empty($jogadoresNegativos)): ?>
                    <div style="background: #ff4444; color: white; padding: 10px; border-radius: 8px; margin-top: 15px;">
                        <strong>⚠️ ALERTA: Jogadores com saldo negativo:</strong>
                        <ul>
                            <?php foreach ($jogadoresNegativos as $jogador):
                                // Usar $dadosSalvos em vez de $dadosFiltrados para pegar o primeiro_negativo
                                $primeiroNegativo = $dadosSalvos[$jogador]["primeiro_negativo"] ?? null;
                                $diasNegativo = null;

                                if ($primeiroNegativo) {
                                    $dataPrimeiroNegativo = to_ts($primeiroNegativo);
                                    $dias = floor((time() - $dataPrimeiroNegativo) / (60 * 60 * 24));
                                    $diasNegativo = $dias . " dia" . ($dias != 1 ? "s" : "");
                                }
                                ?>
                                <li>
                                    <?= htmlspecialchars($jogador) ?>
                                    <?php if ($primeiroNegativo): ?>
                                        - Negativo desde <?= htmlspecialchars($primeiroNegativo) ?>
                                        (<?= $diasNegativo ?>)
                                    <?php else: ?>
                                        - Data do primeiro negativo não registrada
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            </div>
        </form>

        <!-- Gráficos para o Histórico Completo -->
        <div class="charts">
            <div class="panel"><canvas id="barChartHistorico"></canvas></div>
            <div class="panel"><canvas id="pieChartHistorico"></canvas></div>
        </div>
        <script>
            // Dados para os gráficos do histórico
            const labelsHistorico = <?= json_encode(array_keys($dadosFiltrados)) ?>;
            const depositoHistorico = <?= json_encode(array_column($dadosFiltrados, 'deposito')) ?>;
            const saqueHistorico = <?= json_encode(array_column($dadosFiltrados, 'saque')) ?>;
            const totaisHistorico = <?= json_encode(array_column($dadosFiltrados, 'total')) ?>;

            // Gráfico de barras para histórico
            new Chart(document.getElementById('barChartHistorico'), {
                type: 'bar',
                data: {
                    labels: labelsHistorico,
                    datasets: [
                        {
                            label: 'Depósitos',
                            data: depositoHistorico,
                            backgroundColor: '#4caf50'
                        },
                        {
                            label: 'Saques',
                            data: saqueHistorico,
                            backgroundColor: '#f44336'
                        }
                    ]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            labels: { color: "#fff" }
                        }
                    },
                    scales: {
                        x: { ticks: { color: "#fff" } },
                        y: { ticks: { color: "#fff" } }
                    }
                }
            });

            // Gráfico de pizza para histórico
            new Chart(document.getElementById('pieChartHistorico'), {
                type: 'pie',
                data: {
                    labels: labelsHistorico,
                    datasets: [{
                        data: totaisHistorico,
                        backgroundColor: [
                            '#0078d7', '#00bcd4', '#4caf50', '#f44336', '#ff9800',
                            '#9c27b0', '#03a9f4', '#8bc34a', '#e91e63', '#795548',
                            '#607d8b', '#ff5722', '#009688', '#673ab7', '#3f51b5'
                        ]
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            labels: { color: "#fff" }
                        }
                    }
                }
            });

            function limparFiltroHistorico() {
                document.querySelector('input[name="data_inicio_historico"]').value = '';
                document.querySelector('input[name="data_fim_historico"]').value = '';
                document.querySelector('form').submit();
            }
        </script>
    </div>

    <script>
        // FUNÇÕES GLOBAIS
        function ordenarTabela(tableId) {
            let tabela = document.getElementById(tableId);
            let linhas = Array.from(tabela.rows).slice(1, -1); // ignora cabeçalho e TOTAL GERAL
            let coluna = document.getElementById(tableId === 'resultadoTabela' ? 'ordenarSelect' : 'ordenarSelectHistorico').value;

            linhas.sort((a, b) => {
                let valA = a.cells[coluna].innerText.trim();
                let valB = b.cells[coluna].innerText.trim();

                // numérico nas colunas 1+
                if (coluna > 0) {
                    return parseInt(valB) - parseInt(valA); // ordem decrescente
                }
                return valA.localeCompare(valB); // ordem alfabética
            });

            // Reinserir as linhas ordenadas (antes do TOTAL GERAL)
            const tbody = tabela.tBodies[0];
            const totalGeralRow = tbody.rows[tbody.rows.length - 1]; // última linha (TOTAL GERAL)

            // Remover todas as linhas exceto o TOTAL GERAL
            while (tbody.rows.length > 1) {
                tbody.deleteRow(0);
            }

            // Adicionar as linhas ordenadas
            linhas.forEach(l => tbody.insertBefore(l, totalGeralRow));
        }

        function filtrarTabela(tableId) {
            let filtro = document.getElementById(tableId === 'resultadoTabela' ? 'filtroJogador' : 'filtroJogadorHistorico').value.toLowerCase();
            let linhas = document.querySelectorAll(`#${tableId} tbody tr`);

            // Ignorar a última linha (TOTAL GERAL) no filtro
            for (let i = 0; i < linhas.length - 1; i++) {
                let linha = linhas[i];
                let jogador = linha.cells[0].innerText.toLowerCase();
                linha.style.display = jogador.includes(filtro) ? "" : "none";
            }
        }

        function showTab(tabName) {
            // Esconde todos os conteúdos
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });

            // Mostra o conteúdo selecionado
            document.getElementById('tab-' + tabName).classList.add('active');

            // Atualiza as abas ativas
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });

            // Encontra a aba clicada e a marca como ativa
            const tabs = document.querySelectorAll('.tab');
            tabs.forEach(tab => {
                if (tab.textContent.includes(tabName === 'resultado' ? 'Resultado' : 'Histórico')) {
                    tab.classList.add('active');
                }
            });
        }

        function limparFiltroHistorico() {
            document.querySelector('input[name="data_inicio_historico"]').value = '';
            document.querySelector('input[name="data_fim_historico"]').value = '';
            document.querySelector('form').submit();
        }

        // Inicializar abas ao carregar a página
        document.addEventListener('DOMContentLoaded', function () {
            // Garantir que a aba correta esteja visível
            const urlParams = new URLSearchParams(window.location.search);
            const tabParam = urlParams.get('tab');

            if (tabParam) {
                showTab(tabParam);
            } else {
                // Mostrar a primeira aba por padrão
                showTab(<?= !empty($resultado) ? "'resultado'" : "'historico'" ?>);
            }
        });
    </script>
</body>

</html>