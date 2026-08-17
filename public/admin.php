      <tbody>
      <?php foreach ($rows as $s): 
        $st = $s['status'] ?? 'none';
        $now = new DateTime();
        $expiryDate = null;
        $remainingText = '';
        $remainingClass = '';

        if ($st === 'active_trial' && $s['trial_ends_at']) {
            $expiryDate = new DateTime($s['trial_ends_at']);
        } elseif ($s['period_ends_at']) {
            $expiryDate = new DateTime($s['period_ends_at']);
        }

        if ($expiryDate) {
            $diff = $now->diff($expiryDate);
            if ($now > $expiryDate) {
                $remainingText = 'Expired';
                $remainingClass = 'expired';
            } else {
                $remainingText = $diff->days > 0 ? "{$diff->days}d {$diff->h}h left" : "{$diff->h}h {$diff->i}m left";
            }
        }
      ?>
        <tr data-id="<?= $s['id'] ?>" data-name="<?= htmlspecialchars($s['name'], ENT_QUOTES) ?>" data-email="<?= htmlspecialchars($s['email'], ENT_QUOTES) ?>">
          <!-- 1 · CUSTOMER -->
          <td class="name"><b><?= htmlspecialchars($s['name']) ?></b><div class="email"><?= htmlspecialchars($s['email']) ?></div></td>

          <!-- 2 · PLAN -->
          <td><?= $s['plan'] ?: 'No Plan' ?> <span style="color:var(--faint)">(RM <?= $s['price'] ?>)</span></td>

          <!-- 3 · STATUS -->
          <td><span class="badge <?= htmlspecialchars($st) ?>"><?= htmlspecialchars($st) ?></span></td>

          <!-- 4 · START -->
          <td class="date-pair">
            <span><small>Registered:</small> <b class="mono"><?= $s['created_at'] ? date('M d, Y', strtotime($s['created_at'])) : '—' ?></b></span>
            <span><small>Payment:</small> <b class="mono"><?= $s['first_payment'] ? date('M d, Y', strtotime($s['first_payment'])) : '—' ?></b></span>
          </td>

          <!-- 5 · EXPIRY  ← must be its OWN <td>, not inside Start -->
          <td class="date-pair">
            <?php if ($expiryDate): ?>
              <span><b class="mono"><?php
                if ($st === 'active_trial') echo '⏱ ' . $expiryDate->format('M d, H:i');
                else echo $expiryDate->format('M d, Y');
              ?></b></span>
              <?php if ($remainingText): ?>
                <span class="remaining <?= $remainingClass ?>"><?= $remainingText ?></span>
              <?php endif; ?>
            <?php else: ?>
              <span class="mono">—</span>
            <?php endif; ?>
          </td>

          <!-- 6 · TOTAL SALE -->
          <td class="sale">RM <?= number_format((float)$s['total_sale'], 0) ?></td>

          <!-- 7 · ACTIONS -->
          <td><div class="actions">
            <button class="ibtn" data-edit title="Edit profile">✏️</button>
            <button class="ibtn" title="Impersonate (coming soon)" onclick="impersonate('<?= htmlspecialchars(addslashes($s['name']), ENT_QUOTES) ?>')">🎭</button>
            <form method="POST" class="action-form" onsubmit="return confirm('Permanently delete <?= htmlspecialchars(addslashes($s['name']), ENT_QUOTES) ?> (<?= htmlspecialchars($s['email'], ENT_QUOTES) ?>) and ALL related bookings, transactions & invoices?\nThis cannot be undone.')">
              <input type="hidden" name="action" value="delete_user">
              <input type="hidden" name="user_id" value="<?= $s['id'] ?>">
              <button class="ibtn del" title="Delete user + related data (testing)">🗑️</button>
            </form>
            <?php if (in_array($st, ['active_trial','suspended','past_due','none'])): ?>
              <form method="POST" class="action-form"><input type="hidden" name="action" value="extend_trial"><input type="hidden" name="user_id" value="<?= $s['id'] ?>">
              <button class="abtn trial" title="Reset trial to configured default">⏱ +<?= $trialHours ?>h</button></form>
            <?php endif; ?>
            <?php if ($st !== 'active'): ?>
              <form method="POST" class="action-form"><input type="hidden" name="action" value="activate"><input type="hidden" name="user_id" value="<?= $s['id'] ?>">
              <button class="abtn go">Activate (90d)</button></form>
            <?php else: ?>
              <form method="POST" class="action-form"><input type="hidden" name="action" value="suspend"><input type="hidden" name="user_id" value="<?= $s['id'] ?>">
              <button class="abtn stop">Suspend</button></form>
            <?php endif; ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="7" style="text-align:center;padding:40px">No subscribers match your search.</td></tr><?php endif; ?>
      </tbody>
