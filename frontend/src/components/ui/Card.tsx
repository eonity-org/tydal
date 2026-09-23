import {
  Card as MuiCard,
  CardProps as MuiCardProps,
  CardHeader as MuiCardHeader,
  CardHeaderProps as MuiCardHeaderProps,
  CardContent as MuiCardContent,
  CardContentProps as MuiCardContentProps,
  CardActions as MuiCardActions,
  CardActionsProps as MuiCardActionsProps,
} from '@mui/material'

export interface CardProps extends MuiCardProps {
  /**
   * Card content
   */
  children: React.ReactNode
}

/**
 * TYDAL Card Component
 *
 * A container component for grouping related content.
 * Used for facet cards, resource cards, etc.
 *
 * @example
 * ```tsx
 * <Card>
 *   <CardHeader title="Resource Type" />
 *   <CardContent>
 *     <Checkbox label="Images" />
 *     <Checkbox label="Videos" />
 *   </CardContent>
 * </Card>
 * ```
 */
function Card(props: CardProps) {
  const { children, ...muiProps } = props
  return <MuiCard elevation={0} {...muiProps}>{children}</MuiCard>
}

export interface CardHeaderProps extends Omit<MuiCardHeaderProps, 'action'> {
  /**
   * Title text
   */
  title?: React.ReactNode
  /**
   * Subtitle text
   */
  subheader?: React.ReactNode
  /**
   * Action element to display on the right side (typically IconButton)
   */
  action?: React.ReactNode
  /**
   * Avatar to display
   */
  avatar?: React.ReactNode
}

/**
 * CardHeader Component
 *
 * Header section for cards with optional title, subtitle, and action.
 */
function CardHeader(props: CardHeaderProps) {
  const { title, subheader, action, avatar, ...muiProps } = props
  return (
    <MuiCardHeader
      title={title}
      subheader={subheader}
      action={action}
      avatar={avatar}
      {...muiProps}
    />
  )
}

export interface CardContentProps extends MuiCardContentProps {
  /**
   * Content to display
   */
  children: React.ReactNode
}

/**
 * CardContent Component
 *
 * Content section for cards.
 */
function CardContent(props: CardContentProps) {
  const { children, ...muiProps } = props
  return <MuiCardContent {...muiProps}>{children}</MuiCardContent>
}

export interface CardActionsProps extends MuiCardActionsProps {
  /**
   * Action buttons to display
   */
  children: React.ReactNode
}

/**
 * CardActions Component
 *
 * Action buttons section for cards (typically aligned to the right).
 */
function CardActions(props: CardActionsProps) {
  const { children, ...muiProps } = props
  return <MuiCardActions {...muiProps}>{children}</MuiCardActions>
}

Card.Header = CardHeader
Card.Content = CardContent
Card.Actions = CardActions

export default Card
