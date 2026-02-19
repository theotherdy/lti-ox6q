import { useEffect, useMemo, useState } from 'react'
import { Alert } from '@instructure/ui-alerts'
import { Button } from '@instructure/ui-buttons'
import { View } from '@instructure/ui-view'
import { Text } from '@instructure/ui-text'
import { Heading } from '@instructure/ui-heading'
import { Flex } from '@instructure/ui-flex'

const SUPPORTED_TYPES = [
  'multiple_choice_single_answer',
  'multiple_choice_multiple_answer',
  'matching',
  'fill_in_blank',
  'ordering',
  'numeric',
]

function normalize(text) {
  return String(text ?? '').trim().toLowerCase()
}

function shuffledCopy(items) {
  const xs = [...items]
  for (let i = xs.length - 1; i > 0; i -= 1) {
    const j = Math.floor(Math.random() * (i + 1))
    const tmp = xs[i]
    xs[i] = xs[j]
    xs[j] = tmp
  }
  return xs
}

function validateStructuredPayload(pkg) {
  if (!pkg || pkg.kind !== 'structured_question_set') return 'Unsupported structured payload.'
  if (!Array.isArray(pkg.questions) || pkg.questions.length !== 1) return 'Structured payload must contain exactly one question.'

  const q = pkg.questions[0]
  if (!q || !SUPPORTED_TYPES.includes(q.question_type)) return `Unsupported question type: ${q?.question_type || 'unknown'}.`
  if (!q.id || !q.prompt_html) return 'Question requires id and prompt_html.'

  if (q.question_type === 'multiple_choice_single_answer') {
    if (!Array.isArray(q.options) || q.options.length < 2) return 'Question requires at least two options.'
    if (!q.correct_option_id) return 'Question is missing correct option metadata.'
    return null
  }

  if (q.question_type === 'multiple_choice_multiple_answer') {
    if (!Array.isArray(q.options) || q.options.length < 2) return 'Question requires at least two options.'
    if (!Array.isArray(q.correct_option_ids) || q.correct_option_ids.length < 1) return 'Question is missing correct answers metadata.'
    return null
  }

  if (q.question_type === 'matching') {
    if (!Array.isArray(q.prompts) || !Array.isArray(q.choices) || !Array.isArray(q.correct_matches)) {
      return 'Matching question is missing prompts/choices/matches.'
    }
    return null
  }

  if (q.question_type === 'fill_in_blank') {
    if (!Array.isArray(q.blanks) || q.blanks.length < 1) return 'Fill in the blank question is missing blanks.'
    return null
  }

  if (q.question_type === 'ordering') {
    if (!Array.isArray(q.items) || !Array.isArray(q.correct_order)) return 'Ordering question is missing items/order.'
    return null
  }

  if (q.question_type === 'numeric') {
    if (!['exact', 'tolerance'].includes(q.answer_mode)) return 'Numeric question has invalid answer_mode.'
    return null
  }

  return null
}

function renderPrompt(promptHtml) {
  return <span dangerouslySetInnerHTML={{ __html: promptHtml }} />
}

function statusTag(text, color) {
  return <span style={{ color, fontWeight: 700 }}>{text}</span>
}

export default function StructuredQuestionRunner({ pkg }) {
  const q = pkg?.questions?.[0] || {}
  const [submitted, setSubmitted] = useState(false)
  const [singleChoice, setSingleChoice] = useState('')
  const [multiChoice, setMultiChoice] = useState({})
  const [matches, setMatches] = useState({})
  const [blanks, setBlanks] = useState({})
  const [order, setOrder] = useState([])
  const [numeric, setNumeric] = useState('')
  const [attemptCount, setAttemptCount] = useState(0)
  const [lastSubmissionCorrect, setLastSubmissionCorrect] = useState(false)
  const [revealUnlocked, setRevealUnlocked] = useState(false)
  const [sessionNonce, setSessionNonce] = useState(0)

  const validationError = useMemo(() => validateStructuredPayload(pkg), [pkg])
  const revealEnabled = q.reveal_correct_after_two_incorrect_attempts !== false

  function resetQuestionSession() {
    setSubmitted(false)
    setSingleChoice('')
    setMultiChoice({})
    setMatches({})
    setBlanks({})
    setOrder([])
    setNumeric('')
    setAttemptCount(0)
    setLastSubmissionCorrect(false)
    setRevealUnlocked(false)
    setSessionNonce((n) => n + 1)
  }

  useEffect(() => {
    resetQuestionSession()
  }, [q.id, q.question_type])

  const displayedOptions = useMemo(() => {
    if (!Array.isArray(q.options)) return []
    if (!q.shuffle_options) return q.options
    return shuffledCopy(q.options)
  }, [q.id, q.question_type, q.shuffle_options, q.options, sessionNonce])

  const orderIds = useMemo(() => {
    if (q.question_type !== 'ordering') return []
    if (order.length > 0) return order
    return (q.items || []).map((i) => i.id)
  }, [q, order])

  if (validationError) {
    return <Alert variant="error">{validationError}</Alert>
  }

  function isComplete() {
    switch (q.question_type) {
      case 'multiple_choice_single_answer':
        return Boolean(singleChoice)
      case 'multiple_choice_multiple_answer':
        return Object.values(multiChoice).some(Boolean)
      case 'matching':
        return q.prompts.every((p) => Boolean(matches[p.id]))
      case 'fill_in_blank':
        return q.blanks.every((b) => normalize(blanks[b.id]).length > 0)
      case 'ordering':
        return orderIds.length === q.items.length
      case 'numeric':
        return normalize(numeric).length > 0
      default:
        return false
    }
  }

  function getResult() {
    switch (q.question_type) {
      case 'multiple_choice_single_answer':
        return singleChoice === q.correct_option_id
      case 'multiple_choice_multiple_answer': {
        const selectedIds = Object.keys(multiChoice).filter((id) => multiChoice[id])
        const selectedSet = new Set(selectedIds)
        const correctSet = new Set(q.correct_option_ids)
        if (selectedSet.size !== correctSet.size) return false
        for (const id of selectedSet) {
          if (!correctSet.has(id)) return false
        }
        return true
      }
      case 'matching': {
        const expected = new Map(q.correct_matches.map((m) => [m.prompt_id, m.choice_id]))
        for (const prompt of q.prompts) {
          if (matches[prompt.id] !== expected.get(prompt.id)) return false
        }
        return true
      }
      case 'fill_in_blank': {
        for (const blank of q.blanks) {
          const answer = normalize(blanks[blank.id])
          const ok = blank.acceptable_answers.some((a) => normalize(a) === answer)
          if (!ok) return false
        }
        return true
      }
      case 'ordering': {
        if (orderIds.length !== q.correct_order.length) return false
        return orderIds.every((id, idx) => id === q.correct_order[idx])
      }
      case 'numeric': {
        const input = Number(numeric)
        if (Number.isNaN(input)) return false
        if (q.answer_mode === 'exact') return input === Number(q.correct_value)
        if (typeof q.target_value === 'number' && typeof q.tolerance === 'number') {
          return Math.abs(input - Number(q.target_value)) <= Number(q.tolerance)
        }
        if (typeof q.min_value === 'number' && typeof q.max_value === 'number') {
          return input >= Number(q.min_value) && input <= Number(q.max_value)
        }
        return false
      }
      default:
        return false
    }
  }

  function moveOrder(index, direction) {
    const xs = [...orderIds]
    const next = index + direction
    if (next < 0 || next >= xs.length) return
    const tmp = xs[index]
    xs[index] = xs[next]
    xs[next] = tmp
    setOrder(xs)
    setSubmitted(false)
  }

  function onCheckAnswer() {
    if (!isComplete()) return
    const correct = getResult()
    const nextAttempt = attemptCount + 1
    setSubmitted(true)
    setAttemptCount(nextAttempt)
    setLastSubmissionCorrect(correct)

    if (!correct && revealEnabled && nextAttempt >= 3) {
      setRevealUnlocked(true)
    }
  }

  function getNumericExpectedText() {
    if (q.question_type !== 'numeric') return ''
    if (q.answer_mode === 'exact') return `Correct answer: ${q.correct_value}`
    if (typeof q.target_value === 'number' && typeof q.tolerance === 'number') {
      return `Accepted answer: ${q.target_value} +/- ${q.tolerance}`
    }
    if (typeof q.min_value === 'number' && typeof q.max_value === 'number') {
      return `Accepted range: ${q.min_value} to ${q.max_value}`
    }
    return ''
  }

  const shouldRevealCorrect = revealUnlocked || (submitted && lastSubmissionCorrect)
  const canCheckAnswer = attemptCount < 3
  const matchingExpected = new Map((q.correct_matches || []).map((m) => [m.prompt_id, m.choice_id]))

  function getFeedbackMessage() {
    if (submitted) {
      if (lastSubmissionCorrect) return 'Correct.'
      if (shouldRevealCorrect) return "Not quite. Here's the correct answer."
      return `Not quite. Try again. Attempt ${attemptCount} of 3.`
    }
    if (revealUnlocked) return 'Correct answer shown after multiple attempts.'
    return ''
  }

  return (
    <View as="div" borderWidth="small" borderRadius="medium" padding="medium" background="primary">
      <Flex direction="column" gap="medium">
        <div>
          <Heading level="h3" margin="0 0 x-small 0">{pkg.title || 'Question'}</Heading>
          <Text color="secondary">{q.points_possible ?? 1} point{(q.points_possible ?? 1) === 1 ? '' : 's'}</Text>
        </div>

        <fieldset style={{ border: 0, margin: 0, padding: 0 }}>
          <legend style={{ marginBottom: '0.75rem', fontWeight: 600 }}>{renderPrompt(q.prompt_html)}</legend>

          {q.question_type === 'multiple_choice_single_answer' && (
            <Flex as="div" direction="column" gap="small">
              {displayedOptions.map((option) => {
                const inputId = `${q.id}-${option.id}`
                const isCorrectOption = option.id === q.correct_option_id
                const isIncorrectSelection = singleChoice === option.id && !isCorrectOption
                return (
                  <label key={option.id} htmlFor={inputId} style={{ display: 'flex', gap: '0.5rem', alignItems: 'flex-start', cursor: 'pointer' }}>
                    <input
                      id={inputId}
                      type="radio"
                      name={q.id}
                      value={option.id}
                      checked={singleChoice === option.id}
                      onChange={() => {
                        setSingleChoice(option.id)
                        setSubmitted(false)
                      }}
                      aria-label={`Answer: ${option.text}`}
                    />
                    <span>
                      {option.text}{' '}
                      {shouldRevealCorrect && isCorrectOption ? statusTag('[Correct]', 'green') : null}
                      {shouldRevealCorrect && isIncorrectSelection ? statusTag('[Incorrect]', 'red') : null}
                    </span>
                  </label>
                )
              })}
            </Flex>
          )}

          {q.question_type === 'multiple_choice_multiple_answer' && (
            <Flex as="div" direction="column" gap="small">
              {displayedOptions.map((option) => {
                const inputId = `${q.id}-${option.id}`
                const isCorrectOption = (q.correct_option_ids || []).includes(option.id)
                const isIncorrectSelection = Boolean(multiChoice[option.id]) && !isCorrectOption
                return (
                  <label key={option.id} htmlFor={inputId} style={{ display: 'flex', gap: '0.5rem', alignItems: 'flex-start', cursor: 'pointer' }}>
                    <input
                      id={inputId}
                      type="checkbox"
                      checked={Boolean(multiChoice[option.id])}
                      onChange={(e) => {
                        setMultiChoice((xs) => ({ ...xs, [option.id]: e.target.checked }))
                        setSubmitted(false)
                      }}
                      aria-label={`Answer: ${option.text}`}
                    />
                    <span>
                      {option.text}{' '}
                      {shouldRevealCorrect && isCorrectOption ? statusTag('[Correct]', 'green') : null}
                      {shouldRevealCorrect && isIncorrectSelection ? statusTag('[Incorrect]', 'red') : null}
                    </span>
                  </label>
                )
              })}
            </Flex>
          )}

          {q.question_type === 'matching' && (
            <Flex as="div" direction="column" gap="small">
              {q.prompts.map((prompt) => {
                const expectedId = matchingExpected.get(prompt.id)
                const selectedId = matches[prompt.id]
                const isCorrect = selectedId === expectedId
                const expectedText = (q.choices || []).find((choice) => choice.id === expectedId)?.text || ''

                return (
                  <label key={prompt.id} style={{ display: 'flex', flexDirection: 'column', gap: '0.35rem' }}>
                    <span>{prompt.text}</span>
                    <select
                      value={selectedId || ''}
                      onChange={(e) => {
                        setMatches((xs) => ({ ...xs, [prompt.id]: e.target.value }))
                        setSubmitted(false)
                      }}
                      aria-label={`Match for ${prompt.text}`}
                    >
                      <option value="">Select a match</option>
                      {q.choices.map((choice) => (
                        <option key={choice.id} value={choice.id}>{choice.text}</option>
                      ))}
                    </select>
                    {shouldRevealCorrect && (
                      <Text>
                        {isCorrect ? statusTag('[Correct] ', 'green') : statusTag('[Incorrect] ', 'red')}
                        {!isCorrect && expectedText ? `Correct: ${expectedText}` : ''}
                      </Text>
                    )}
                  </label>
                )
              })}
            </Flex>
          )}

          {q.question_type === 'fill_in_blank' && (
            <Flex as="div" direction="column" gap="small">
              {q.blanks.map((blank) => {
                const studentAnswer = blanks[blank.id] || ''
                const isCorrect = blank.acceptable_answers.some((a) => normalize(a) === normalize(studentAnswer))
                return (
                  <label key={blank.id} style={{ display: 'flex', flexDirection: 'column', gap: '0.35rem' }}>
                    <span>Blank: {blank.id}</span>
                    <input
                      type="text"
                      value={studentAnswer}
                      onChange={(e) => {
                        setBlanks((xs) => ({ ...xs, [blank.id]: e.target.value }))
                        setSubmitted(false)
                      }}
                      aria-label={`Answer for ${blank.id}`}
                    />
                    {shouldRevealCorrect && (
                      <Text>
                        {isCorrect ? statusTag('[Correct] ', 'green') : statusTag('[Incorrect] ', 'red')}
                        {!isCorrect ? `Accepted: ${blank.acceptable_answers.join(', ')}` : ''}
                      </Text>
                    )}
                  </label>
                )
              })}
            </Flex>
          )}

          {q.question_type === 'ordering' && (
            <Flex as="div" direction="column" gap="small">
              {orderIds.map((id, index) => {
                const item = q.items.find((x) => x.id === id)
                if (!item) return null
                const isCorrectPosition = q.correct_order[index] === id
                return (
                  <Flex key={id} alignItems="center" gap="small" wrap="wrap">
                    <Text>{index + 1}. {item.text}</Text>
                    <Button size="small" onClick={() => moveOrder(index, -1)} disabled={index === 0}>Up</Button>
                    <Button size="small" onClick={() => moveOrder(index, 1)} disabled={index === orderIds.length - 1}>Down</Button>
                    {shouldRevealCorrect && (
                      <Text>
                        {isCorrectPosition ? statusTag('[Correct]', 'green') : statusTag('[Incorrect]', 'red')}
                      </Text>
                    )}
                  </Flex>
                )
              })}
            </Flex>
          )}

          {q.question_type === 'numeric' && (
            <Flex as="div" direction="column" gap="x-small">
              <label style={{ display: 'flex', flexDirection: 'column', gap: '0.35rem' }}>
                <span>Numeric answer</span>
                <input
                  type="number"
                  value={numeric}
                  onChange={(e) => {
                    setNumeric(e.target.value)
                    setSubmitted(false)
                  }}
                  aria-label="Numeric answer"
                  style={{ textAlign: 'right' }}
                />
              </label>
              {shouldRevealCorrect && (
                <Text>
                  {lastSubmissionCorrect ? statusTag('[Correct] ', 'green') : statusTag('[Incorrect] ', 'red')}
                  {getNumericExpectedText()}
                </Text>
              )}
            </Flex>
          )}
        </fieldset>

        <Flex gap="small" alignItems="center">
          {canCheckAnswer ? (
            <Button onClick={onCheckAnswer} disabled={!isComplete()} color="primary">
              Check answer
            </Button>
          ) : (
            <Button onClick={resetQuestionSession} color="primary">
              Start again
            </Button>
          )}
        </Flex>

        {(submitted || revealUnlocked) && (
          <Alert variant={lastSubmissionCorrect ? 'success' : 'warning'}>
            <Text aria-live="polite">{getFeedbackMessage()}</Text>
          </Alert>
        )}
      </Flex>
    </View>
  )
}
