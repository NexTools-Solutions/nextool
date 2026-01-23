<?php
/**
 * Serviço responsável por sugerir respostas ao solicitante.
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

class PluginNextoolAiassistReplyService {

   /** @var PluginNextoolAiassist */
   private $module;

   /** @var PluginNextoolAiassistProviderInterface */
   private $provider;

   public function __construct(PluginNextoolAiassist $module) {
      $this->module = $module;
      $this->provider = $module->getProviderInstance();
   }

   /**
    * Gera sugestão de resposta para o ticket.
    *
    * @param int $ticketId
    * @param int $userId
    * @param array $options
    * @return array
    */
   public function suggest($ticketId, $userId, array $options = []) {
      Toolbox::logInFile('plugin_nextool_aiassist', sprintf(
         '[REPLY] Iniciando sugestão - Ticket #%d, User #%d',
         $ticketId,
         $userId
      ));
      
      $ticket = new Ticket();
      if (!$ticket->getFromDB($ticketId)) {
         Toolbox::logInFile('plugin_nextool_aiassist', "[REPLY] Ticket #$ticketId não encontrado");
         return [
            'success' => false,
            'message' => __('Chamado não encontrado.', 'nextool'),
         ];
      }

      $context = $this->module->buildTicketContext($ticket, [
         'limit_followups' => 6
      ]);

      $contextText = $this->buildCompactContext($ticket, $context);

      if ($contextText === '') {
         return [
            'success' => false,
            'message' => __('Não há histórico suficiente para sugerir uma resposta.', 'nextool'),
         ];
      }

      // Verificar se deve usar cache (a menos que force=true)
      $force = !empty($options['force']);
      
      if (!$force) {
         $ticketData = $this->module->getTicketData($ticketId);
         $cachedReply = trim((string)($ticketData['reply_text'] ?? ''));
         $lastReplyFollowupId = (int)($ticketData['last_reply_followup_id'] ?? 0);
         $currentLastFollowupId = (int)($context['last_followup_id'] ?? 0);
         
         // Se já tem cache E não há novo followup, retornar cache
         if ($cachedReply !== '' && $lastReplyFollowupId === $currentLastFollowupId) {
            Toolbox::logInFile('plugin_nextool_aiassist', sprintf(
               '[REPLY] Retornando sugestão em cache (sem novos followups) - Ticket #%d',
               $ticketId
            ));
            return [
               'success' => true,
               'content' => $cachedReply,
               'from_cache' => true,
               'cached_at' => $ticketData['last_reply_at'] ?? null
            ];
         }
      }

      $estimatedTokens = $this->module->estimateTokensFromText($contextText);
      if (!$this->module->hasTokensAvailable($estimatedTokens)) {
         return [
            'success' => false,
            'message' => __('Limite de tokens excedido. Ajuste o saldo ou aguarde o próximo ciclo.', 'nextool'),
         ];
      }

      // Obtém nome do analista para assinatura dinâmica
      $analystName = $this->module->getUserDisplayName($userId);
      
      $tone = $options['tone'] ?? __('profissional e cordial', 'nextool');
      $instructions = sprintf(
         "Com base no histórico abaixo, redija uma resposta %s para o solicitante, informando status atual e próximos passos. Inclua saudação inicial e FINALIZE com:\n\nAtenciosamente,\n%s",
         $tone,
         $analystName
      );

      $response = $this->provider->chat([
         [
            'role' => 'system',
            'content' => 'Responda em português do Brasil e mantenha tom empático e claro.'
         ],
         [
            'role' => 'user',
            'content' => $instructions . "\n\n" . $contextText
         ],
      ], [
         'model' => $this->module->getFeatureModel(PluginNextoolAiassist::FEATURE_REPLY),
         'max_tokens' => 2000,
         'temperature' => 0.4,
         'metadata' => [
            'feature' => PluginNextoolAiassist::FEATURE_REPLY,
            'ticket_id' => $ticketId,
         ]
      ]);

      $this->module->logFeatureRequest([
         'tickets_id' => $ticketId,
         'users_id' => $userId,
         'feature' => PluginNextoolAiassist::FEATURE_REPLY,
         'success' => $response['success'] ?? false,
         'tokens_prompt' => $response['tokens_prompt'] ?? $estimatedTokens,
         'tokens_completion' => $response['tokens_completion'] ?? 0,
         'payload_hash' => $context['payload_hash'] ?? null,
         'error_message' => $response['error'] ?? null
      ]);

      if (!empty($response['success'])) {
         $finishReason = $response['raw']['candidates'][0]['finishReason'] ?? null;
         if ($finishReason === 'MAX_TOKENS') {
            Toolbox::logInFile('plugin_nextool_aiassist', sprintf(
               '[REPLY] Resposta interrompida por MAX_TOKENS - Ticket #%d',
               $ticketId
            ));
         }
         $this->module->saveReplyData($ticketId, [
            'reply_text' => $response['content'],
            'last_followup_id' => $context['last_followup_id']
         ]);
      }

      return $response;
   }

   /**
    * Monta um contexto compacto para a sugestão de resposta.
    * Prioriza descrição inicial, últimos followups públicos e solução.
    */
   private function buildCompactContext(Ticket $ticket, array $context): string {
      $maxTotalChars = 3500;
      $maxDescriptionChars = 1200;
      $maxFollowupChars = 800;
      $maxSolutionChars = 800;
      $maxFollowups = 3;

      $parts = [];
      $parts[] = sprintf(
         "Chamado #%d - %s\nStatus: %s | Prioridade: %s",
         (int)$ticket->getID(),
         trim((string)($ticket->fields['name'] ?? '')),
         trim((string)($ticket->fields['status'] ?? '')),
         trim((string)($ticket->fields['priority'] ?? ''))
      );

      $description = trim(strip_tags((string)($ticket->fields['content'] ?? '')));
      if ($description !== '') {
         if (mb_strlen($description) > $maxDescriptionChars) {
            $description = mb_substr($description, 0, $maxDescriptionChars - 3) . '...';
         }
         $parts[] = "Descrição inicial:\n" . $description;
      }

      $followupsText = $this->extractFollowupsFromContext($context['text'] ?? '', $maxFollowups, $maxFollowupChars);
      if ($followupsText !== '') {
         $parts[] = "Últimas interações:\n" . $followupsText;
      }

      $solutionText = $this->extractSolutionFromContext($context['text'] ?? '', $maxSolutionChars);
      if ($solutionText !== '') {
         $parts[] = "Solução registrada:\n" . $solutionText;
      }

      $compact = trim(implode("\n\n", $parts));
      if (mb_strlen($compact) > $maxTotalChars) {
         $compact = mb_substr($compact, 0, $maxTotalChars - 3) . '...';
      }

      return trim($compact);
   }

   private function extractFollowupsFromContext(string $contextText, int $limit, int $maxCharsPerItem): string {
      if ($contextText === '') {
         return '';
      }

      $sections = explode("Interações:\n", $contextText);
      if (count($sections) < 2) {
         return '';
      }

      $interactionsText = trim($sections[1]);
      if ($interactionsText === '') {
         return '';
      }

      $items = preg_split('/\n{2,}/', $interactionsText);
      $items = array_values(array_filter($items, function ($item) {
         return trim($item) !== '';
      }));

      if (empty($items)) {
         return '';
      }

      $slice = array_slice($items, max(0, count($items) - $limit));
      $normalized = [];
      foreach ($slice as $item) {
         $item = trim($item);
         if (mb_strlen($item) > $maxCharsPerItem) {
            $item = mb_substr($item, 0, $maxCharsPerItem - 3) . '...';
         }
         $normalized[] = $item;
      }

      return trim(implode("\n\n", $normalized));
   }

   private function extractSolutionFromContext(string $contextText, int $maxChars): string {
      if ($contextText === '') {
         return '';
      }

      $sections = explode("Solução registrada:\n", $contextText);
      if (count($sections) < 2) {
         return '';
      }

      $solutionText = trim($sections[1]);
      if ($solutionText === '') {
         return '';
      }

      if (mb_strlen($solutionText) > $maxChars) {
         $solutionText = mb_substr($solutionText, 0, $maxChars - 3) . '...';
      }

      return $solutionText;
   }
}
