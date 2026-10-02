export const wallInteractionTitle = status => ({
  approaching: 'Approaching', testing: 'Testing the level', touch: 'Touched the level',
  bounce: 'Bounced from the level', rejection: 'Rejected at the level', break: 'First close through',
  acceptance_above: 'Accepted above', acceptance_below: 'Accepted below', reclaim: 'Reclaimed the level',
  failed_reclaim: 'Retest failed', confirmed_breakout: 'Confirmed break higher', confirmed_breakdown: 'Confirmed break lower',
}[status] || 'Watching the level')
