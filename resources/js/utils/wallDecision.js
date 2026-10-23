export const wallReactionTitle = status => ({
  approaching: 'Price is approaching', testing: 'Price is testing the level', touch: 'Price touched the level',
  bounce: 'Price bounced higher', rejection: 'Price moved lower after a touch', break: 'Price closed through the level',
  acceptance_above: 'Two closes above the level', acceptance_below: 'Two closes below the level',
  reclaim: 'Price returned across the level', failed_reclaim: 'Retest did not reclaim the level',
  confirmed_breakout: 'Price moved higher after a retest', confirmed_breakdown: 'Price moved lower after a retest',
}[status] || 'Watching for a price reaction')

export function findWallAssessment(interaction, id) {
  if (!id) return undefined
  return interaction?.events?.find(row => row.id === id) || interaction?.readings?.find(row => row.id === id)
}

export function wallDecisionCards(interaction, latestReading, selectedEvent) {
  return (selectedEvent ? [selectedEvent.side] : ['put', 'call']).map(side => {
    const current = interaction.current?.[side]
    const strike = selectedEvent?.strike ?? latestReading?.walls?.[side]?.[0]?.strike ?? current?.strike
    const earlier = [...(interaction.events || [])].reverse().find(event => event.side === side && event.strike === strike)
    const matchingCurrent = current?.strike === strike ? current : null
    const reaction = selectedEvent || (matchingCurrent?.status && matchingCurrent.status !== 'unknown' ? matchingCurrent : earlier || matchingCurrent)
    const setup = selectedEvent
      ? [...(interaction.setups?.readings || [])].reverse().find(row => row.side === side && row.observed_at === selectedEvent.observed_at)
      : interaction.setups?.current?.[side]
    const matchingSetup = setup?.strike === strike ? setup : null
    const hasLevels = [matchingSetup?.entry_zone?.low, matchingSetup?.entry_zone?.high,
      matchingSetup?.invalidation?.price, matchingSetup?.target?.strike].every(Number.isFinite)
    const ready = matchingSetup?.status === 'conditions_met' && hasLevels
      && (!!selectedEvent || ['ready', 'demonstration'].includes(interaction.state))
    return { side, strike, reaction, isEarlier: !selectedEvent && reaction === earlier && reaction !== current,
      setup: matchingSetup, ready, pending: matchingSetup?.pattern && matchingSetup.status === 'watch' }
  })
}

export function savedWallNextCondition(card) {
  const next = card.setup?.next_condition
  return next?.version === 'wall-next-condition.v1' && next.setup_status === card.setup.status
    && typeof next.text === 'string' ? next : null
}
